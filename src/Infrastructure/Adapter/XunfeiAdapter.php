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
 * 讯飞星火平台适配器
 * 
 * 实现讯飞星火大模型 API 的统一接口封装。
 * 使用 APPID + API Key + API Secret 三元组认证，通过 WebSocket 签名。
 * 
 * 讯飞星火使用 WebSocket 协议进行流式通信，也支持 HTTP 同步请求。
 * 
 * @package Kode\AiAgent\Infrastructure\Adapter
 * 
 * @example
 * ```php
 * $adapter = new XunfeiAdapter($client, [
 *     'app_id' => 'your-app-id',
 *     'api_key' => 'your-api-key',
 *     'api_secret' => 'your-api-secret',
 *     'model' => 'generalv3.5',
 * ]);
 * ```
 */
final readonly class XunfeiAdapter implements AdapterInterface
{
    use StreamHelper;

    private const BASE_URL = 'https://spark-api-open.xf-yun.com/v1';
    private const DEFAULT_MODEL = 'generalv3.5';
    private const DEFAULT_TIMEOUT = 60;
    private const DOMAIN_MAP = [
        'generalv1' => 'general',
        'generalv2' => 'generalv2',
        'generalv3' => 'generalv3',
        'generalv3.5' => 'generalv3.5',
        '4.0Ultra' => '4.0Ultra',
        'max-32k' => 'max-32k',
    ];

    private ?string $appId;
    private ?ApiKey $apiKey;
    private ?string $apiSecret;

    public function __construct(
        private HttpClient $client,
        private array $config,
    ) {
        $this->validateConfig();
        $this->appId = $this->config['app_id'] ?? null;
        $this->apiKey = $this->resolveApiKey();
        $this->apiSecret = $this->config['api_secret'] ?? $this->apiKey?->secret();
    }

    #[\NoDiscard]
    public function send(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        $startTime = microtime(true);

        try {
            $response = $this->sendRequest($prompt, $options);
            $duration = microtime(true) - $startTime;

            $content = $response['choices'][0]['message']['content'] ?? '';

            $responseObj = new Response(
                content: $content,
                choices: $response['choices'] ?? [],
                usage: $response['usage'] ?? [],
            );

            return $responseObj->with([
                'duration' => $duration,
                'model' => $response['model'] ?? $this->resolveModel($options),
                'requestId' => $response['id'] ?? '',
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
            $url = $this->buildSignedUrl(true);
            $headers = [
                'Content-Type' => 'application/json',
            ];

            $request = new Request(
                'POST',
                $url,
                $headers,
                json_encode($body)
            );

            $response = $this->client->sendRequest($request);
            $stream = $response->getBody();

            while (!$stream->eof()) {
                $line = $this->readLine($stream);

                if ($line === '') {
                    continue;
                }

                $data = $this->parseSseLine($line);

                if ($data !== null && isset($data['choices'][0]['delta']['content'])) {
                    yield $data['choices'][0]['delta']['content'];
                }
            }
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->resolveBaseUrl(), $e);
        }
    }

    public function name(): string
    {
        return 'xunfei';
    }

    private function sendRequest(PromptInterface $prompt, array $options): array
    {
        $body = $this->buildRequestBody($prompt, $options);
        $url = $this->buildSignedUrl(false);
        $headers = [
            'Content-Type' => 'application/json',
        ];

        $request = new Request(
            'POST',
            $url,
            $headers,
            json_encode($body)
        );

        $response = $this->client->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 401 || $statusCode === 403) {
            throw AuthenticationException::invalidApiKey('xunfei');
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

        if (isset($data['error'])) {
            $errorCode = $data['error']['code'] ?? 'UnknownError';
            $errorMsg = $data['error']['message'] ?? 'Unknown error';

            if (in_array($errorCode, ['invalid_api_key', 'invalid_app_id', 'invalid_signature'])) {
                throw AuthenticationException::invalidApiKey('xunfei: ' . $errorMsg);
            }

            if ($errorCode === 'rate_limit_exceeded') {
                throw \Kode\AiAgent\Exception\RateLimitException::requestsPerMinute(0);
            }

            throw PlatformException::serverError(500, "{$errorCode}: {$errorMsg}");
        }

        return $data;
    }

    private function buildSignedUrl(bool $stream): string
    {
        $model = $this->resolveModel([]);
        $endpoint = $stream ? 'chat/completions/stream' : 'chat/completions';
        $baseUrl = $this->resolveBaseUrl();

        $timestamp = time();
        $date = gmdate('Y-m-d\TH:i:s\Z', $timestamp);

        $signatureOrigin = "host: spark-api-open.xf-yun.com\n";
        $signatureOrigin .= "date: {$date}\n";
        $signatureOrigin .= "GET /v1/{$endpoint} HTTP/1.1";

        $signature = base64_encode(hash_hmac('sha256', $signatureOrigin, $this->apiSecret, true));

        $authorizationOrigin = "api_key=\"{$this->apiKey->value()}\", ";
        $authorizationOrigin .= "algorithm=\"hmac-sha256\", ";
        $authorizationOrigin .= "headers=\"host date request-line\", ";
        $authorizationOrigin .= "signature=\"{$signature}\"";

        $authorization = base64_encode($authorizationOrigin);

        $params = [
            'authorization' => $authorization,
            'date' => $date,
            'host' => 'spark-api-open.xf-yun.com',
        ];

        return "{$baseUrl}/{$endpoint}?" . http_build_query($params);
    }

    private function buildRequestBody(PromptInterface $prompt, array $options, bool $stream = false): array
    {
        $model = $this->resolveModel($options);
        $domain = self::DOMAIN_MAP[$model] ?? $model;

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt->text()],
            ],
        ];

        if ($this->appId !== null) {
            $body['app_id'] = $this->appId;
        }

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = (int) $options['max_tokens'];
        }

        if (isset($options['top_p'])) {
            $body['top_p'] = (float) $options['top_p'];
        }

        if ($stream) {
            $body['stream'] = true;
        }

        return $body;
    }

    private function validateConfig(): void
    {
        $hasApiKey = !empty($this->config['api_key']);
        $hasApiSecret = !empty($this->config['api_secret']);
        $hasAppKey = !empty($this->config['app_key']) && !empty($this->config['app_secret']);

        if (!($hasApiKey && $hasApiSecret) && !$hasAppKey) {
            throw ConfigurationException::missing('api_key + api_secret 或 app_key + app_secret');
        }
    }

    private function resolveApiKey(): ?ApiKey
    {
        if (!empty($this->config['app_key']) && !empty($this->config['app_secret'])) {
            return ApiKey::appSecret(
                $this->config['app_key'],
                $this->config['app_secret']
            );
        }

        if (!empty($this->config['api_key']) && !empty($this->config['api_secret'])) {
            return ApiKey::appSecret(
                $this->config['api_key'],
                $this->config['api_secret']
            );
        }

        if (!empty($this->config['api_key'])) {
            return ApiKey::fromString($this->config['api_key']);
        }

        return null;
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
