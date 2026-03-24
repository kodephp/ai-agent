<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\Response;
use Kode\AiAgent\Exception\{AuthenticationException, ConfigurationException, PlatformException};
use Kode\HttpClient\HttpClient;
use Nyholm\Psr7\Request;

/**
 * Anthropic 平台适配器
 * 
 * 实现 Anthropic API 的统一接口封装，支持同步和流式响应。
 * 使用 kode/http-client 进行 HTTP 请求。
 * 
 * @package Kode\AiAgent\Infrastructure\Adapter
 */
readonly class AnthropicAdapter implements AdapterInterface
{
    use StreamHelper;

    private const BASE_URL = 'https://api.anthropic.com/v1/messages';
    private const DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';
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

            $responseObj = new Response(
                content: $response['content'][0]['text'] ?? '',
                choices: $response['content'] ?? [],
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
        $options['stream'] = true;
        $body = $this->buildRequestBody($prompt, $options);

        try {
            $request = $this->createRequest($body);
            $response = $this->client->sendRequest($request);
            $stream = $response->getBody();

            while (!$stream->eof()) {
                $line = $this->readLine($stream);
                
                if ($line === '') {
                    continue;
                }

                $data = $this->parseSseLine($line);
                
                if ($data !== null && isset($data['delta']['text'])) {
                    yield $data['delta']['text'];
                }
            }
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->resolveBaseUrl(), $e);
        }
    }

    public function name(): string
    {
        return 'anthropic';
    }

    private function sendRequest(PromptInterface $prompt, array $options): array
    {
        $body = $this->buildRequestBody($prompt, $options);
        $request = $this->createRequest($body);

        $response = $this->client->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 401) {
            throw AuthenticationException::invalidApiKey('anthropic');
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

    private function createRequest(array $body): \Psr\Http\Message\RequestInterface
    {
        return new Request(
            'POST',
            $this->resolveBaseUrl(),
            [
                'x-api-key' => $this->resolveApiKey(),
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            json_encode($body)
        );
    }

    private function buildRequestBody(PromptInterface $prompt, array $options): array
    {
        $body = [
            'model' => $this->resolveModel($options),
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => [
                ['role' => 'user', 'content' => $prompt->text()],
            ],
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['system'])) {
            $body['system'] = $options['system'];
        }

        if (isset($options['stream']) && $options['stream']) {
            $body['stream'] = true;
        }

        return $body;
    }

    private function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw ConfigurationException::missing('api_key');
        }

        if (!$this->validateApiKeyFormat($this->config['api_key'], 'sk-ant-')) {
            throw ConfigurationException::invalid('api_key', '无效的 Anthropic API Key 格式');
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
