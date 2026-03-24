<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\Response;
use Kode\AiAgent\Exception\{AuthenticationException, ConfigurationException, PlatformException};
use Kode\HttpClient\HttpClient;
use Nyholm\Psr7\Request;

/**
 * Google Gemini 平台适配器
 * 
 * 实现 Google Gemini API 的统一接口封装，支持同步和流式响应。
 * 使用 kode/http-client 进行 HTTP 请求。
 * 
 * @package Kode\AiAgent\Infrastructure\Adapter
 */
readonly class GeminiAdapter implements AdapterInterface
{
    use StreamHelper;

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const DEFAULT_MODEL = 'gemini-2.0-flash';
    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_CONNECT_TIMEOUT = 5;
    private const STREAM_IDLE_TIMEOUT = 60;

    public function __construct(
        private HttpClient $client,
        private array $config,
    ) {
        $this->validateConfig();
        $this->config['base_url'] = $this->ensureHttps($this->config['base_url'] ?? self::BASE_URL);
    }

    #[\NoDiscard]
    public function send(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        $startTime = microtime(true);

        try {
            $response = $this->sendRequest($prompt, $options);
            $duration = microtime(true) - $startTime;

            $content = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

            $responseObj = new Response(
                content: $content,
                choices: $response['candidates'] ?? [],
                usage: $response['usageMetadata'] ?? [],
            );
            return $responseObj->with([
                'duration' => $duration,
                'model' => $this->resolveModel($options),
                'requestId' => '',
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
        $body = $this->buildRequestBody($prompt, $options);

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

                if ($data !== null && isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    yield $data['candidates'][0]['content']['parts'][0]['text'];
                }
            }
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->resolveBaseUrl(), $e);
        }
    }

    public function name(): string
    {
        return 'gemini';
    }

    private function sendRequest(PromptInterface $prompt, array $options): array
    {
        $body = $this->buildRequestBody($prompt, $options);
        $request = $this->createRequest($body);

        $response = $this->client->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 401 || $statusCode === 403) {
            throw AuthenticationException::invalidApiKey('gemini');
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
            throw PlatformException::serverError(
                $statusCode,
                $data['error']['message'] ?? 'Unknown error'
            );
        }

        return $data;
    }

    private function createRequest(array $body, bool $stream = false): \Psr\Http\Message\RequestInterface
    {
        $model = $body['model'] ?? self::DEFAULT_MODEL;
        $method = $stream ? 'streamGenerateContent' : 'generateContent';
        $url = $this->resolveBaseUrl() . "/{$model}:{$method}?key=" . $this->resolveApiKey();

        return new Request(
            'POST',
            $url,
            [
                'Content-Type' => 'application/json',
            ],
            json_encode($body)
        );
    }

    private function buildRequestBody(PromptInterface $prompt, array $options = []): array
    {
        $body = [
            'model' => $this->resolveModel($options),
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt->text()],
                    ],
                ],
            ],
        ];

        $generationConfig = [];

        if (isset($options['temperature'])) {
            $generationConfig['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = (int) $options['max_tokens'];
        }

        if (isset($options['top_p'])) {
            $generationConfig['topP'] = (float) $options['top_p'];
        }

        if (!empty($generationConfig)) {
            $body['generationConfig'] = $generationConfig;
        }

        return $body;
    }

    private function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw ConfigurationException::missing('api_key');
        }

        if (strlen($this->config['api_key']) < 16) {
            throw ConfigurationException::invalid('api_key', '无效的 Gemini API Key 格式');
        }
    }

    private function resolveApiKey(): string
    {
        return $this->config['api_key'];
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
