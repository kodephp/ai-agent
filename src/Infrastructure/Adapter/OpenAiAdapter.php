<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, ImageGeneratorInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\{ImageResponse, Response};
use Kode\AiAgent\Exception\{AuthenticationException, ConfigurationException, InvalidInputException, InvalidResponseException, PlatformException, RateLimitException};
use Kode\AiAgent\Support\JsonParser;
use Kode\HttpClient\HttpClientInterface;
use Nyholm\Psr7\Request;

/**
 * OpenAI 平台适配器
 * 
 * 实现 OpenAI API 的统一接口封装，支持同步和流式响应。
 * 使用 kode/http-client 进行 HTTP 请求。
 * 
 * @package Kode\AiAgent\Infrastructure\Adapter
 */
final class OpenAiAdapter implements AdapterInterface, ImageGeneratorInterface
{
    use StreamHelper;

    private const BASE_URL = 'https://api.openai.com/v1/chat/completions';
    private const IMAGES_BASE_URL = 'https://api.openai.com/v1/images/generations';
    private const DEFAULT_MODEL = 'gpt-4o';
    private const DEFAULT_IMAGE_MODEL = 'dall-e-3';

    /** 图像编辑端点默认模型（dall-e-3 不支持 edits/variations，dall-e-2 为公共最小集） */
    private const DEFAULT_IMAGE_EDIT_MODEL = 'dall-e-2';

    /** 图像变体端点默认模型（同上） */
    private const DEFAULT_IMAGE_VARIATION_MODEL = 'dall-e-2';

    public function __construct(
        private HttpClientInterface $client,
        private array $config,
    ) {
        $this->validateConfig();
        $this->config['base_url'] = $this->ensureHttps($this->config['base_url'] ?? self::BASE_URL);
    }

    #[\Override]
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

    #[\Override]
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

    #[\Override]
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

    #[\Override]
    #[\NoDiscard]
    public function generateImage(string $prompt, array $options = []): ImageResponse
    {
        $this->validateImagePrompt($prompt);

        $body = $this->buildImageRequestBody($prompt, $options);

        return $this->executeImageRequest(
            $this->createImageRequest($body),
            $options,
            $this->resolveImagesBaseUrl()
        );
    }

    #[\Override]
    #[\NoDiscard]
    public function editImage(string $image, string $prompt, array $options = []): ImageResponse
    {
        $this->validateImagePrompt($prompt);
        [$content, $filename, $mime] = $this->resolveImageBytes($image, $options);

        $files = [
            ['name' => 'image', 'filename' => $filename, 'mime' => $mime, 'content' => $content],
        ];

        // 可选蒙版（透明区域指示编辑范围），与 image 同为文件上载字段
        if (isset($options['mask']) && is_string($options['mask']) && $options['mask'] !== '') { // @phpstan-ignore booleanAnd.rightAlwaysTrue (用户输入数组无形状保证，保留空串护栏)
            [$maskContent, $maskFilename, $maskMime] = $this->resolveImageBytes($options['mask'], []);
            $files[] = ['name' => 'mask', 'filename' => $maskFilename, 'mime' => $maskMime, 'content' => $maskContent];
        }

        $url = $this->resolveImagesUrl('/edits');
        $fields = $this->buildImageOptionFields($options, self::DEFAULT_IMAGE_EDIT_MODEL);
        $fields['prompt'] = $prompt;

        $request = $this->createMultipartRequest($url, $fields, $files);

        return $this->executeImageRequest($request, $options, $url, self::DEFAULT_IMAGE_EDIT_MODEL);
    }

    #[\Override]
    #[\NoDiscard]
    public function generateImageVariation(string $image, array $options = []): ImageResponse
    {
        [$content, $filename, $mime] = $this->resolveImageBytes($image, $options);

        $url = $this->resolveImagesUrl('/variations');
        $fields = $this->buildImageOptionFields($options, self::DEFAULT_IMAGE_VARIATION_MODEL);

        $request = $this->createMultipartRequest($url, $fields, [
            ['name' => 'image', 'filename' => $filename, 'mime' => $mime, 'content' => $content],
        ]);

        return $this->executeImageRequest($request, $options, $url, self::DEFAULT_IMAGE_VARIATION_MODEL);
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

    /**
     * 统一执行图像端点请求：HTTP + 状态码映射 + JSON 解析 + ImageResponse 组装
     *
     * @throws AuthenticationException 401 时
     * @throws RateLimitException 429 时
     * @throws PlatformException 5xx / 4xx 拒绝 / 解析失败时
     */
    private function executeImageRequest(
        \Psr\Http\Message\RequestInterface $request,
        array $options,
        string $endpoint,
        string $fallbackModel = self::DEFAULT_IMAGE_MODEL
    ): ImageResponse {
        $startTime = microtime(true);

        try {
            $response = $this->client->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 401) {
                throw AuthenticationException::invalidApiKey('openai');
            }

            if ($statusCode === 429) {
                throw RateLimitException::requestsPerMinute(0);
            }

            if ($statusCode >= 500) {
                throw PlatformException::serverError($statusCode, $response->getReasonPhrase());
            }

            $content = $response->getBody()->getContents();

            try {
                $raw = JsonParser::parseArray($content);
            } catch (InvalidResponseException $e) {
                throw PlatformException::responseParseFailed(
                    $e->getMessage(),
                    ['raw' => substr($content, 0, 200)]
                );
            }

            if ($statusCode >= 400) {
                // 4xx 不再静默返回空图：把 OpenAI 的 error.message 抛给调用方
                $message = is_array($raw['error'] ?? null)
                    ? (string) ($raw['error']['message'] ?? '')
                    : (string) ($raw['error'] ?? '');
                if ($message === '') {
                    $message = $response->getReasonPhrase();
                }
                throw new PlatformException(
                    "OpenAI 图像接口拒绝请求 ({$statusCode}): {$message}",
                    1008,
                    ['status_code' => $statusCode, 'endpoint' => $endpoint]
                );
            }

            $duration = microtime(true) - $startTime;

            $images = array_map(
                fn($item) => $item['url'] ?? $item['b64_json'] ?? '',
                $raw['data'] ?? []
            );

            return (new ImageResponse(
                images: $images,
                revisedPrompt: $raw['data'][0]['revised_prompt'] ?? '',
            ))->with([
                'duration' => $duration,
                'model' => $options['model'] ?? $fallbackModel,
                // created 是 int（strict_types 下直传 string 参数会 TypeError）
                'requestId' => (string) ($raw['created'] ?? ''),
            ]);
        } catch (PlatformException | AuthenticationException | InvalidInputException | RateLimitException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($endpoint, $e);
        }
    }

    /**
     * multipart 表单的通用图像参数（model/size/n/response_format，按需携带）
     *
     * @return array<string, string>
     */
    private function buildImageOptionFields(array $options, string $defaultModel): array
    {
        $fields = ['model' => $options['model'] ?? $defaultModel];

        if (isset($options['size'])) {
            $fields['size'] = (string) $options['size'];
        }
        if (isset($options['n'])) {
            $fields['n'] = (string) (int) $options['n'];
        }
        if (isset($options['response_format'])) {
            $fields['response_format'] = (string) $options['response_format'];
        }

        return $fields;
    }

    /**
     * 构造 multipart/form-data POST 请求（edits / variations 端点要求文件上载）
     *
     * @param array<string, string> $fields 普通字段（null 值跳过）
     * @param array<int, array{name: string, filename: string, mime: string, content: string}> $files 文件字段
     */
    private function createMultipartRequest(string $url, array $fields, array $files): \Psr\Http\Message\RequestInterface
    {
        $boundary = '----kode-openai-' . bin2hex(random_bytes(16));
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
            $body .= $value . "\r\n";
        }

        foreach ($files as $file) {
            $filename = str_replace(['\\', '"'], ['\\\\', '\\"'], $file['filename']);
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $file['name'] . '"; filename="' . $filename . '"' . "\r\n";
            $body .= 'Content-Type: ' . $file['mime'] . "\r\n\r\n";
            $body .= $file['content'] . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return new Request('POST', $url, [
            'Authorization' => 'Bearer ' . $this->resolveApiKey(),
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ], $body);
    }

    /**
     * 解析图像输入为可上载字节（data URI / 纯 base64 / http(s) URL 远程拉取）
     *
     * @param array{image_filename?: string} $options
     *
     * @return array{0: string, 1: string, 2: string} [content, filename, mime]
     *
     * @throws InvalidInputException 输入非法或解码失败时
     * @throws PlatformException 远程拉取失败时
     */
    private function resolveImageBytes(string $image, array $options): array
    {
        if ($image === '') {
            throw new InvalidInputException('图像不能为空');
        }

        if (str_starts_with($image, 'data:')) {
            $comma = strpos($image, ',');
            if ($comma === false) {
                throw new InvalidInputException('非法 data URI 图像');
            }

            $meta = substr($image, 5, $comma - 5);
            $payload = substr($image, $comma + 1);
            $isBase64 = str_ends_with($meta, ';base64');
            $mime = $isBase64 ? substr($meta, 0, -7) : $meta;

            $content = $isBase64 ? base64_decode($payload, true) : rawurldecode($payload);
            if ($content === false || $content === '') {
                throw new InvalidInputException('图像 base64 解码失败');
            }

            if ($mime === '' || $mime === 'application/octet-stream') {
                $mime = $this->sniffImageMime($content);
            }

            return [$content, $options['image_filename'] ?? ('image.' . $this->mimeExtension($mime)), $mime];
        }

        if (preg_match('#^https?://#i', $image) === 1) {
            try {
                $response = $this->client->sendRequest(new Request('GET', $image));
            } catch (\Throwable $e) {
                throw PlatformException::connectionFailed($image, $e);
            }

            if ($response->getStatusCode() >= 400) {
                throw new InvalidInputException(
                    "拉取远程图像失败 ({$response->getStatusCode()}): {$image}"
                );
            }

            $content = (string) $response->getBody();
            if ($content === '') {
                throw new InvalidInputException("远程图像内容为空: {$image}");
            }

            $contentType = $response->getHeaderLine('Content-Type');
            $mime = str_contains($contentType, 'image/')
                ? trim(explode(';', $contentType)[0])
                : $this->sniffImageMime($content);

            $path = (string) (parse_url($image, PHP_URL_PATH) ?? '');
            $filename = basename($path);
            if ($filename === '' || !str_contains($filename, '.')) {
                $filename = 'image.' . $this->mimeExtension($mime);
            }

            return [$content, $options['image_filename'] ?? $filename, $mime];
        }

        // 本地文件路径（README 用法：editImage('/path/to/original.jpg', ...)）
        if (strlen($image) < 4096 && @is_file($image)) {
            $content = file_get_contents($image);
            if ($content === false || $content === '') {
                throw new InvalidInputException("读取本地图像失败: {$image}");
            }

            $filename = basename($image);

            return [$content, $options['image_filename'] ?? $filename, $this->sniffImageMime($content)];
        }

        $content = base64_decode($image, true);
        if ($content === false || $content === '') {
            throw new InvalidInputException('图像须为 base64 字符串、data URI 或 http(s) URL');
        }

        $mime = $this->sniffImageMime($content);

        return [$content, $options['image_filename'] ?? ('image.' . $this->mimeExtension($mime)), $mime];
    }

    /** 按魔数嗅探图像 MIME（识别失败默认 image/png）。 */
    private function sniffImageMime(string $bytes): string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return 'image/png';
    }

    /** MIME → 文件扩展名（未知退化为 png）。 */
    private function mimeExtension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }

    /**
     * 解析 edits / variations 端点 URL
     *
     * images_edit_url / images_variation_url 配置优先；
     * 否则从 images_base_url（默认 generations 端点）去掉尾部 /generations 拼接。
     */
    private function resolveImagesUrl(string $suffix): string
    {
        $explicit = match ($suffix) {
            '/edits' => $this->config['images_edit_url'] ?? null,
            '/variations' => $this->config['images_variation_url'] ?? null,
            default => null,
        };

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $base = $this->resolveImagesBaseUrl();
        if (str_ends_with($base, '/generations')) {
            $base = substr($base, 0, -strlen('/generations'));
        }

        return rtrim($base, '/') . $suffix;
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
