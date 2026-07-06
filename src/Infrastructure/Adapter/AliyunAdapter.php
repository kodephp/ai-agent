<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\Response;
use Kode\AiAgent\Domain\ValueObject\ApiKey;
use Kode\AiAgent\Exception\{AuthenticationException, ConfigurationException, PlatformException};
use Kode\HttpClient\HttpClient;
use Nyholm\Psr7\Request;

/**
 * 阿里云通义千问平台适配器
 * 
 * 实现阿里云通义千问 API 的统一接口封装。
 * 支持多种认证方式：
 * - API Key（Bearer Token）
 * - AppKey + AppSecret（签名认证）
 * 
 * 使用 kode/http-client 进行 HTTP 请求。
 * 
 * @package Kode\AiAgent\Infrastructure\Adapter
 */
final class AliyunAdapter implements AdapterInterface
{
    use StreamHelper;

    private const BASE_URL = 'https://dashscope.aliyuncs.com/api/v1/services/aigc/text-generation/generation';
    private const DEFAULT_MODEL = 'qwen-turbo';
    private ?ApiKey $apiKey;

    public function __construct(
        private HttpClient $client,
        private array $config,
    ) {
        $this->validateConfig();
        $this->config['base_url'] = $this->ensureHttps($this->config['base_url'] ?? self::BASE_URL);
        $this->apiKey = $this->resolveApiKey();
    }

    #[\NoDiscard]
    public function send(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        $startTime = microtime(true);

        try {
            $response = $this->sendRequest($prompt, $options);
            $duration = microtime(true) - $startTime;

            $content = $response['output']['text'] 
                ?? $response['output']['choices'][0]['message']['content'] 
                ?? '';

            $responseObj = new Response(
                content: $content,
                choices: $response['output']['choices'] ?? [],
                usage: $response['usage'] ?? [],
            );
            return $responseObj->with([
                'duration' => $duration,
                'model' => $response['model'] ?? $this->resolveModel($options),
                'requestId' => $response['request_id'] ?? '',
            ]);
        } catch (PlatformException | AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->resolveBaseUrl(), $e);
        }
    }

    #[\NoDiscard]
    public function stream(PromptInterface $prompt, array $options = []): \Generator
    {
        $body = $this->buildRequestBody($prompt, $options, true);

        try {
            $request = $this->createRequest($body, true);
            $response = $this->client->sendRequest($request);
            $stream = $response->getBody();

            while (!$stream->eof()) {
                $line = $this->readLine($stream);

                if ($line === '') {
                    continue;
                }

                $data = $this->parseSseLine($line);

                if ($data !== null) {
                    $content = $data['output']['text'] 
                        ?? $data['output']['choices'][0]['message']['content'] 
                        ?? null;

                    if ($content !== null) {
                        yield $content;
                    }
                }
            }
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->resolveBaseUrl(), $e);
        }
    }

    public function name(): string
    {
        return 'aliyun';
    }

    private function sendRequest(PromptInterface $prompt, array $options): array
    {
        $body = $this->buildRequestBody($prompt, $options);
        $request = $this->createRequest($body);

        $response = $this->client->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 401 || $statusCode === 403) {
            throw AuthenticationException::invalidApiKey('aliyun');
        }

        if ($statusCode === 429) {
            throw \Kode\AiAgent\Exception\RateLimitException::requestsPerMinute(0);
        }

        if ($statusCode >= 500) {
            throw PlatformException::serverError($statusCode, $response->getReasonPhrase());
        }

        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw PlatformException::responseParseFailed(
                'JSON 解析失败: ' . json_last_error_msg(),
                ['raw' => substr($content, 0, 200)]
            );
        }

        if (isset($data['code']) && $data['code'] !== 'Success' && $data['code'] !== '200') {
            throw PlatformException::serverError(
                (int) ($data['code'] ?? 500),
                $data['message'] ?? 'Unknown error'
            );
        }

        return $data;
    }

    private function createRequest(array $body, bool $stream = false): \Psr\Http\Message\RequestInterface
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        // 根据认证方式设置请求头
        if ($this->apiKey !== null && $this->apiKey->isAppSecretMode()) {
            // AppKey + AppSecret 签名认证
            $headers['X-DashScope-AppKey'] = $this->apiKey->appKey();
            
            // 生成签名
            $timestamp = time();
            $nonce = bin2hex(random_bytes(16));
            $stringToSign = json_encode($body);
            $signature = hash_hmac('sha256', $stringToSign, $this->apiKey->secret());
            
            $headers['X-DashScope-Timestamp'] = (string) $timestamp;
            $headers['X-DashScope-Nonce'] = $nonce;
            $headers['X-DashScope-Signature'] = $signature;
        } else {
            // Bearer Token 认证
            $headers['Authorization'] = 'Bearer ' . $this->resolveApiKeyString();
        }

        if ($stream) {
            $headers['X-DashScope-SSE'] = 'enable';
        }

        return new Request(
            'POST',
            $this->resolveBaseUrl(),
            $headers,
            json_encode($body)
        );
    }

    private function buildRequestBody(PromptInterface $prompt, array $options, bool $stream = false): array
    {
        $body = [
            'model' => $this->resolveModel($options),
            'input' => [
                'messages' => [
                    ['role' => 'user', 'content' => $prompt->text()],
                ],
            ],
            'parameters' => [],
        ];

        if (isset($options['temperature'])) {
            $body['parameters']['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $body['parameters']['max_tokens'] = (int) $options['max_tokens'];
        }

        if (isset($options['top_p'])) {
            $body['parameters']['top_p'] = (float) $options['top_p'];
        }

        if ($stream) {
            $body['parameters']['incremental_output'] = true;
        }

        return $body;
    }

    private function validateConfig(): void
    {
        // 支持多种认证配置
        $hasApiKey = !empty($this->config['api_key']);
        $hasAppKey = !empty($this->config['app_key']) && !empty($this->config['app_secret']);
        
        if (!$hasApiKey && !$hasAppKey) {
            throw ConfigurationException::missing('api_key 或 app_key + app_secret');
        }
    }

    private function resolveApiKey(): ?ApiKey
    {
        // AppKey + AppSecret 模式
        if (!empty($this->config['app_key']) && !empty($this->config['app_secret'])) {
            return ApiKey::appSecret(
                $this->config['app_key'],
                $this->config['app_secret'],
                $this->config['extra'] ?? []
            );
        }
        
        // API Key 模式
        if (!empty($this->config['api_key'])) {
            if ($this->config['api_key'] instanceof ApiKey) {
                return $this->config['api_key'];
            }
            
            return ApiKey::fromString($this->config['api_key']);
        }
        
        return null;
    }

    private function resolveApiKeyString(): string
    {
        if ($this->apiKey !== null) {
            return $this->apiKey->value();
        }
        
        return $this->config['api_key'] ?? '';
    }

    private function resolveModel(array $options): string
    {
        return $options['model'] ?? $this->config['model'] ?? self::DEFAULT_MODEL;
    }

    private function resolveBaseUrl(): string
    {
        return $this->config['base_url'] ?? self::BASE_URL;
    }
}
