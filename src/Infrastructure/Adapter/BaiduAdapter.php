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
 * 百度文心一言平台适配器
 * 
 * 实现百度文心一言 API 的统一接口封装。
 * 支持多种认证方式：
 * - API Key + Secret Key（获取 Access Token）
 * - 直接使用 Access Token
 * 
 * 百度文心一言需要先通过 API Key 和 Secret Key 获取 Access Token，
 * 然后使用 Access Token 调用推理 API。
 * 
 * @package Kode\AiAgent\Infrastructure\Adapter
 * 
 * @example
 * ```php
 * // 使用 API Key + Secret Key（推荐，自动获取 Access Token）
 * $adapter = new BaiduAdapter($client, [
 *     'api_key' => 'your-api-key',
 *     'secret_key' => 'your-secret-key',
 * ]);
 * 
 * // 直接使用 Access Token
 * $adapter = new BaiduAdapter($client, [
 *     'access_token' => 'your-access-token',
 * ]);
 * ```
 */
final class BaiduAdapter implements AdapterInterface
{
    use StreamHelper;

    private const BASE_URL = 'https://aip.baidubce.com/rpc/2.0/ai_custom/v1/wenxinworkshop/chat';
    private const TOKEN_URL = 'https://aip.baidubce.com/oauth/2.0/token';
    private const DEFAULT_MODEL = 'completions_pro';

    private ?ApiKey $apiKey;
    private ?string $secretKey;
    private ?string $accessToken;
    private int $tokenExpireTime;

    public function __construct(
        private HttpClient $client,
        private array $config,
    ) {
        $this->validateConfig();
        $this->config['base_url'] = $this->ensureHttps($this->config['base_url'] ?? self::BASE_URL);
        $this->apiKey = $this->resolveApiKey();
        $this->secretKey = $this->config['secret_key'] ?? null;
        $this->accessToken = $this->config['access_token'] ?? null;
        $this->tokenExpireTime = 0;
    }

    #[\NoDiscard]
    public function send(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        $startTime = microtime(true);

        try {
            $response = $this->sendRequest($prompt, $options);
            $duration = microtime(true) - $startTime;

            $content = $response['result'] ?? '';

            $responseObj = new Response(
                content: $content,
                choices: [],
                usage: [
                    'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                    'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                    'total_tokens' => $response['usage']['total_tokens'] ?? 0,
                ],
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
            $token = $this->getAccessToken();
            $url = $this->buildStreamUrl($token);

            $request = new Request(
                'POST',
                $url,
                ['Content-Type' => 'application/json'],
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

                if ($data !== null && isset($data['result'])) {
                    yield $data['result'];
                }
            }
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->resolveBaseUrl(), $e);
        }
    }

    public function name(): string
    {
        return 'baidu';
    }

    private function sendRequest(PromptInterface $prompt, array $options): array
    {
        $body = $this->buildRequestBody($prompt, $options);
        $token = $this->getAccessToken();
        $url = $this->buildApiUrl($token);

        $request = new Request(
            'POST',
            $url,
            ['Content-Type' => 'application/json'],
            json_encode($body)
        );

        $response = $this->client->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 401 || $statusCode === 403) {
            throw AuthenticationException::invalidApiKey('baidu');
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

        if (isset($data['error_code'])) {
            $errorMsg = $data['error_msg'] ?? $data['error_code'];
            
            if ($data['error_code'] === 18 || $data['error_code'] === 19) {
                throw \Kode\AiAgent\Exception\RateLimitException::requestsPerMinute(0);
            }
            
            if ($data['error_code'] === 110 || $data['error_code'] === 111) {
                throw AuthenticationException::invalidApiKey('baidu');
            }

            throw PlatformException::serverError(
                (int) $data['error_code'],
                $errorMsg
            );
        }

        return $data;
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        if ($this->tokenExpireTime > time() + 60) {
            return $this->getCachedToken();
        }

        return $this->fetchAccessToken();
    }

    private function fetchAccessToken(): string
    {
        if ($this->apiKey === null || $this->secretKey === null) {
            throw ConfigurationException::missing('api_key 和 secret_key 或 access_token');
        }

        $url = self::TOKEN_URL . '?' . http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->apiKey->value(),
            'client_secret' => $this->secretKey,
        ]);

        $request = new Request('POST', $url);
        $response = $this->client->sendRequest($request);

        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw PlatformException::responseParseFailed(
                'Access Token 获取失败: JSON 解析错误',
                ['raw' => substr($content, 0, 200)]
            );
        }

        if (isset($data['error'])) {
            throw AuthenticationException::invalidApiKey('baidu: ' . ($data['error_description'] ?? $data['error']));
        }

        if (!isset($data['access_token'])) {
            throw AuthenticationException::invalidApiKey('baidu: 无法获取 access_token');
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpireTime = time() + ($data['expires_in'] ?? 86400);

        return $this->accessToken;
    }

    private function getCachedToken(): string
    {
        return $this->accessToken ?? '';
    }

    private function buildApiUrl(string $token): string
    {
        $model = $this->resolveModel([]);
        $baseUrl = $this->resolveBaseUrl();

        return "{$baseUrl}/{$model}?access_token={$token}";
    }

    private function buildStreamUrl(string $token): string
    {
        $model = $this->resolveModel([]);
        $baseUrl = $this->resolveBaseUrl();

        return "{$baseUrl}/{$model}?access_token={$token}&stream=true";
    }

    private function buildRequestBody(PromptInterface $prompt, array $options, bool $stream = false): array
    {
        $body = [
            'messages' => [
                ['role' => 'user', 'content' => $prompt->text()],
            ],
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $body['max_output_tokens'] = (int) $options['max_tokens'];
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
        $hasAccessToken = !empty($this->config['access_token']);
        $hasApiKeyAndSecret = !empty($this->config['api_key']) && !empty($this->config['secret_key']);

        if (!$hasAccessToken && !$hasApiKeyAndSecret) {
            throw ConfigurationException::missing('access_token 或 api_key + secret_key');
        }
    }

    private function resolveApiKey(): ?ApiKey
    {
        if (!empty($this->config['api_key'])) {
            if ($this->config['api_key'] instanceof ApiKey) {
                return $this->config['api_key'];
            }

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
