<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, ImageGeneratorInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\{ImageResponse, Response};
use Kode\AiAgent\Exception\{AuthenticationException, ConfigurationException, PlatformException, InvalidInputException};
use Kode\AiAgent\Support\JsonParser;
use Kode\HttpClient\HttpClient;
use Nyholm\Psr7\Request;

/**
 * OpenAI 平台适配器
 * 
 * 实现 OpenAI API 的统一接口封装，支持同步和流式响应。
 * 使用 kode/http-client 进行 HTTP 请求。
 * 
 * @package Kode\AiAgent\Infrastructure\Adapter
 */
readonly class OpenAiAdapter implements AdapterInterface, ImageGeneratorInterface
{
    use StreamHelper;

    private const BASE_URL = 'https://api.openai.com/v1/chat/completions';
    private const IMAGES_BASE_URL = 'https://api.openai.com/v1/images/generations';
    private const DEFAULT_MODEL = 'gpt-4o';
    private const DEFAULT_IMAGE_MODEL = 'dall-e-3';
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
                content: $response['choices'][0]['message']['content'] ?? '',
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
        $options['stream'] = true;
        $body = $this->buildRequestBody($prompt, $options);

        try {
            $request = $this->createRequest($body);
            $response = $this->client->sendRequest($request);
            $stream = $response->getBody();

            while (!$stream->eof()) {
                $line = $this->readLine($stream);
                
                if ($line === '' || $this->isStreamDone($line)) {
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
        return 'openai';
    }

    private function sendRequest(PromptInterface $prompt, array $options): array
    {
        $body = $this->buildRequestBody($prompt, $options);
        $request = $this->createRequest($body);

        $response = $this->client->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 401) {
            throw AuthenticationException::invalidApiKey('openai');
        }

        if ($statusCode === 429) {
            throw \Kode\AiAgent\Exception\RateLimitException::requestsPerMinute(0);
        }

        if ($statusCode >= 500) {
            throw PlatformException::serverError($statusCode, $response->getReasonPhrase());
        }

        $content = $response->getBody()->getContents();

        try {
            return JsonParser::parseArray($content);
        } catch (\Kode\AiAgent\Exception\InvalidResponseException $e) {
            throw PlatformException::responseParseFailed(
                $e->getMessage(),
                ['raw' => substr($content, 0, 200)]
            );
        }
    }

    private function createRequest(array $body): \Psr\Http\Message\RequestInterface
    {
        return new Request(
            'POST',
            $this->resolveBaseUrl(),
            [
                'Authorization' => 'Bearer ' . $this->resolveApiKey(),
                'Content-Type' => 'application/json',
            ],
            json_encode($body)
        );
    }

    private function buildRequestBody(PromptInterface $prompt, array $options): array
    {
        $body = [
            'model' => $this->resolveModel($options),
            'messages' => [
                ['role' => 'user', 'content' => $prompt->text()],
            ],
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = (int) $options['max_tokens'];
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

        if (!$this->validateApiKeyFormat($this->config['api_key'], 'sk-')) {
            throw ConfigurationException::invalid('api_key', '无效的 OpenAI API Key 格式');
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

    #[\NoDiscard]
    public function generateImage(string $prompt, array $options = []): ImageResponse
    {
        $this->validateImagePrompt($prompt);
        $startTime = microtime(true);

        try {
            $response = $this->sendImageRequest($prompt, $options);
            $duration = microtime(true) - $startTime;

            $images = array_map(
                fn($item) => $item['url'] ?? $item['b64_json'] ?? '',
                $response['data'] ?? []
            );

            $responseObj = new ImageResponse(
                images: $images,
                revisedPrompt: $response['data'][0]['revised_prompt'] ?? '',
            );

            return $responseObj->with([
                'duration' => $duration,
                'model' => $options['model'] ?? self::DEFAULT_IMAGE_MODEL,
                'requestId' => $response['created'] ?? '',
            ]);
        } catch (PlatformException | AuthenticationException | InvalidInputException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->resolveImagesBaseUrl(), $e);
        }
    }

    #[\NoDiscard]
    public function editImage(string $image, string $prompt, array $options = []): ImageResponse
    {
        throw new PlatformException('图像编辑功能暂未实现', 1004, ['method' => 'editImage']);
    }

    #[\NoDiscard]
    public function generateImageVariation(string $image, array $options = []): ImageResponse
    {
        throw new PlatformException('图像变体生成功能暂未实现', 1004, ['method' => 'generateImageVariation']);
    }

    private function validateImagePrompt(string $prompt): void
    {
        if (empty(trim($prompt))) {
            throw new InvalidInputException('图像描述提示词不能为空');
        }
        if (strlen($prompt) > 4000) {
            throw new InvalidInputException('图像描述提示词长度不能超过4000字符');
        }
    }

    private function sendImageRequest(string $prompt, array $options): array
    {
        $body = $this->buildImageRequestBody($prompt, $options);
        $request = $this->createImageRequest($body);

        $response = $this->client->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 401) {
            throw AuthenticationException::invalidApiKey('openai');
        }

        if ($statusCode === 429) {
            throw \Kode\AiAgent\Exception\RateLimitException::requestsPerMinute(0);
        }

        if ($statusCode >= 500) {
            throw PlatformException::serverError($statusCode, $response->getReasonPhrase());
        }

        $content = $response->getBody()->getContents();

        try {
            return JsonParser::parseArray($content);
        } catch (\Kode\AiAgent\Exception\InvalidResponseException $e) {
            throw PlatformException::responseParseFailed(
                $e->getMessage(),
                ['raw' => substr($content, 0, 200)]
            );
        }
    }

    private function buildImageRequestBody(string $prompt, array $options): array
    {
        $body = [
            'model' => $options['model'] ?? self::DEFAULT_IMAGE_MODEL,
            'prompt' => $prompt,
        ];

        if (isset($options['size'])) {
            $body['size'] = $options['size'];
        }

        if (isset($options['quality'])) {
            $body['quality'] = $options['quality'];
        }

        if (isset($options['style'])) {
            $body['style'] = $options['style'];
        }

        if (isset($options['n'])) {
            $body['n'] = (int) $options['n'];
        }

        if (isset($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        return $body;
    }

    private function createImageRequest(array $body): \Psr\Http\Message\RequestInterface
    {
        return new Request(
            'POST',
            $this->resolveImagesBaseUrl(),
            [
                'Authorization' => 'Bearer ' . $this->resolveApiKey(),
                'Content-Type' => 'application/json',
            ],
            json_encode($body)
        );
    }

    private function resolveImagesBaseUrl(): string
    {
        return $this->config['images_base_url'] ?? self::IMAGES_BASE_URL;
    }
}
