<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface, VideoGeneratorInterface};
use Kode\AiAgent\Domain\Model\{Prompt, Response, VideoResponse};
use Kode\AiAgent\Exception\{AuthenticationException, ConfigurationException, PlatformException, InvalidInputException};
use Kode\HttpClient\HttpClient;
use Nyholm\Psr7\Request;

/**
 * Seedance 视频适配器
 *
 * 字节跳动 Seedance AI 视频生成 API 适配器（兼容 2.0 与 2.5）。
 * 支持文生视频、图生视频、多镜头叙事，原生音频生成。
 *
 * 特性：
 * - 版本可配置：2.0（seedance-2.0-pro/lite）、2.5（seedance-2.5-pro/lite）
 * - 720P / 1080P 分辨率（可配置）
 * - 最长 15 秒视频
 * - 6 种画幅比例
 * - 原生音视频联合生成
 * - 多镜头叙事
 *
 * @package Kode\AiAgent\Infrastructure\Adapter
 *
 * @example
 * ```php
 * $adapter = SeedanceAdapter::create('your-api-key');
 *
 * // 文生视频 (720P 高清)
 * $video = $adapter->generateVideo('一只猫咪在草地上玩耍');
 *
 * // 文生视频 (1080P 全高清)
 * $video = $adapter->generateVideo('一只猫咪在草地上玩耍', [
 *     'resolution' => '1080p',
 * ]);
 *
 * // 图生视频
 * $video = $adapter->imageToVideo('https://example.com/image.jpg', '让猫咪动起来');
 * ```
 */
class SeedanceAdapter implements AdapterInterface, VideoGeneratorInterface
{
    use StreamHelper;

    private const BASE_URL = 'https://ark.cn-beijing.volces.com/api/v3/';
    private const DEFAULT_MODEL = 'seedance-2.0-pro';
    private const DEFAULT_TIMEOUT = 120;
    private const DEFAULT_DURATION = 10;
    private const DEFAULT_RESOLUTION = '720p';
    private const DEFAULT_FPS = 24;

    public const SUPPORTED_RESOLUTIONS = ['720p', '1080p'];

    /**
     * 支持的模型版本
     * - 2.0：seedance-2.0-pro / seedance-2.0-lite（稳定版）
     * - 2.5：seedance-2.5-pro / seedance-2.5-lite（最新版，更强运动一致性）
     */
    public const SUPPORTED_VERSIONS = ['2.0', '2.5'];

    public const VERSION_MODELS = [
        '2.0' => ['pro' => 'seedance-2.0-pro', 'lite' => 'seedance-2.0-lite'],
        '2.5' => ['pro' => 'seedance-2.5-pro', 'lite' => 'seedance-2.5-lite'],
    ];

    public const SUPPORTED_MODELS = [
        'seedance-2.0-pro',
        'seedance-2.0-lite',
        'seedance-2.5-pro',
        'seedance-2.5-lite',
    ];

    public const ASPECT_RATIOS = [
        '16:9' => [1920, 1080],
        '9:16' => [1080, 1920],
        '1:1' => [1080, 1080],
        '4:3' => [1440, 1080],
        '3:4' => [1080, 1440],
        '21:9' => [2560, 1080],
    ];

    private HttpClient $client;
    private array $config;

    public function __construct(HttpClient $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
        $this->validateConfig();
    }

    public static function create(string $apiKey, array $config = []): self
    {
        $client = \Kode\HttpClient\Factory::create([
            'timeout' => $config['timeout'] ?? self::DEFAULT_TIMEOUT,
            'retries' => $config['retries'] ?? 3,
        ]);

        return new self($client, array_merge($config, ['api_key' => $apiKey]));
    }

    #[\NoDiscard]
    public function send(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        $startTime = microtime(true);

        try {
            $response = $this->sendChatRequest($prompt, $options);
            $duration = microtime(true) - $startTime;

            $responseObj = new Response(
                content: $response['choices'][0]['message']['content'] ?? '',
                choices: $response['choices'] ?? [],
                usage: $response['usage'] ?? [],
            );

            return $responseObj->with([
                'duration' => $duration,
                'model' => $response['model'] ?? self::DEFAULT_MODEL,
                'requestId' => $response['id'] ?? '',
            ]);
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->getBaseUrl(), $e);
        }
    }

    #[\NoDiscard]
    public function stream(PromptInterface $prompt, array $options = []): \Generator
    {
        $options['stream'] = true;
        $body = $this->buildChatBody($prompt, $options);

        $request = new Request('POST', $this->getBaseUrl() . 'chat/completions', [
            'Authorization' => 'Bearer ' . $this->config['api_key'],
            'Content-Type' => 'application/json',
        ], json_encode($body));

        try {
            $response = $this->client->sendRequest($request);
            $stream = $response->getBody();

            while (!$stream->eof()) {
                $line = $this->readLine($stream);

                if (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);

                    if ($data === '[DONE]') {
                        break;
                    }

                    $json = json_decode($data, true);

                    if (isset($json['choices'][0]['delta']['content'])) {
                        yield $json['choices'][0]['delta']['content'];
                    }
                }
            }
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->getBaseUrl(), $e);
        }
    }

    public function name(): string
    {
        return 'seedance';
    }

    #[\NoDiscard]
    public function generateVideo(string $prompt, array $options = []): VideoResponse
    {
        $this->validatePrompt($prompt);

        $options = $this->normalizeOptions($options);
        $startTime = microtime(true);

        $resolution = $this->resolveResolution($options['resolution'] ?? self::DEFAULT_RESOLUTION);

        $body = [
            'model' => $this->resolveModel($options),
            'input' => [
                'prompt' => $prompt,
            ],
            'parameters' => [
                'duration' => $options['duration'] ?? self::DEFAULT_DURATION,
                'resolution' => $resolution,
                'fps' => $options['fps'] ?? self::DEFAULT_FPS,
                'aspect_ratio' => $options['aspect_ratio'] ?? '16:9',
            ],
        ];

        if (isset($options['negative_prompt'])) {
            $body['parameters']['negative_prompt'] = $options['negative_prompt'];
        }

        if (isset($options['seed'])) {
            $body['parameters']['seed'] = $options['seed'];
        }

        try {
            $request = new Request('POST', $this->getVideoEndpoint(), [
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Content-Type' => 'application/json',
            ], json_encode($body));

            $response = $this->client->sendRequest($request);
            $result = json_decode($response->getBody()->getContents(), true);

            $duration = microtime(true) - $startTime;

            $videoUrl = $result['data']['video_url'] ?? '';
            $taskId = $result['data']['task_id'] ?? '';

            return (new VideoResponse(
                videos: $videoUrl !== '' ? [$videoUrl] : [],
                videoDuration: $options['duration'] ?? self::DEFAULT_DURATION,
            ))->with([
                'duration' => $duration,
                'requestId' => $result['request_id'] ?? $taskId,
                'model' => $options['model'] ?? self::DEFAULT_MODEL,
                'resolution' => $resolution,
            ]);
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->getVideoEndpoint(), $e);
        }
    }

    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
    {
        $this->validateImageInput($image);

        $options = $this->normalizeOptions($options);
        $startTime = microtime(true);

        $resolution = $this->resolveResolution($options['resolution'] ?? self::DEFAULT_RESOLUTION);

        $input = [
            'image_url' => $this->resolveImageUrl($image),
        ];

        if ($prompt !== null) {
            $input['prompt'] = $prompt;
        }

        $body = [
            'model' => $this->resolveModel($options),
            'input' => $input,
            'parameters' => [
                'duration' => $options['duration'] ?? self::DEFAULT_DURATION,
                'resolution' => $resolution,
                'fps' => $options['fps'] ?? self::DEFAULT_FPS,
                'aspect_ratio' => $options['aspect_ratio'] ?? '16:9',
            ],
        ];

        try {
            $request = new Request('POST', $this->getVideoEndpoint(), [
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Content-Type' => 'application/json',
            ], json_encode($body));

            $response = $this->client->sendRequest($request);
            $result = json_decode($response->getBody()->getContents(), true);

            $duration = microtime(true) - $startTime;

            $videoUrl = $result['data']['video_url'] ?? '';
            $taskId = $result['data']['task_id'] ?? '';

            return (new VideoResponse(
                videos: $videoUrl !== '' ? [$videoUrl] : [],
                videoDuration: $options['duration'] ?? self::DEFAULT_DURATION,
            ))->with([
                'duration' => $duration,
                'requestId' => $result['request_id'] ?? $taskId,
                'model' => $options['model'] ?? self::DEFAULT_MODEL,
                'resolution' => $resolution,
            ]);
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->getVideoEndpoint(), $e);
        }
    }

    /**
     * 生成多镜头视频
     *
     * @param string $prompt 提示词
     * @param int $shots 镜头数量 (2-6)
     * @param array $options 选项
     * @return VideoResponse 多镜头视频响应
     */
    public function generateMultiShot(string $prompt, int $shots = 3, array $options = []): VideoResponse
    {
        $this->validatePrompt($prompt);

        if ($shots < 2 || $shots > 6) {
            throw InvalidInputException::invalidParameter('shots', '2-6');
        }

        $options = $this->normalizeOptions($options);
        $startTime = microtime(true);

        $resolution = $this->resolveResolution($options['resolution'] ?? self::DEFAULT_RESOLUTION);

        $body = [
            'model' => $this->resolveModel($options),
            'input' => [
                'prompt' => $prompt,
            ],
            'parameters' => [
                'duration' => $options['duration'] ?? self::DEFAULT_DURATION,
                'resolution' => $resolution,
                'fps' => $options['fps'] ?? self::DEFAULT_FPS,
                'aspect_ratio' => $options['aspect_ratio'] ?? '16:9',
                'multi_shot' => true,
                'shots' => $shots,
            ],
        ];

        try {
            $request = new Request('POST', $this->getVideoEndpoint(), [
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Content-Type' => 'application/json',
            ], json_encode($body));

            $response = $this->client->sendRequest($request);
            $result = json_decode($response->getBody()->getContents(), true);

            $duration = microtime(true) - $startTime;
            $videos = [];

            if (isset($result['data']['videos']) && is_array($result['data']['videos'])) {
                foreach ($result['data']['videos'] as $video) {
                    if (isset($video['video_url'])) {
                        $videos[] = $video['video_url'];
                    }
                }
            } elseif (isset($result['data']['video_url'])) {
                $videos = [$result['data']['video_url']];
            }

            return (new VideoResponse(
                videos: $videos,
                videoDuration: ($options['duration'] ?? self::DEFAULT_DURATION) * count($videos),
            ))->with([
                'duration' => $duration,
                'requestId' => $result['request_id'] ?? '',
                'model' => $options['model'] ?? self::DEFAULT_MODEL,
                'resolution' => $resolution,
            ]);
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->getVideoEndpoint(), $e);
        }
    }

    /**
     * 获取任务状态
     *
     * @param string $taskId 任务 ID
     * @return array 任务状态
     */
    public function getTaskStatus(string $taskId): array
    {
        try {
            $request = new Request('GET', $this->getVideoEndpoint() . "/{$taskId}", [
                'Authorization' => 'Bearer ' . $this->config['api_key'],
            ]);

            $response = $this->client->sendRequest($request);
            return json_decode($response->getBody()->getContents(), true);
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->getVideoEndpoint(), $e);
        }
    }

    /**
     * 获取默认分辨率
     */
    public function getDefaultResolution(): string
    {
        return self::DEFAULT_RESOLUTION;
    }

    /**
     * 获取支持的分辨率列表
     */
    public function getSupportedResolutions(): array
    {
        return self::SUPPORTED_RESOLUTIONS;
    }

    /**
     * 获取支持的模型版本（2.0 / 2.5）
     */
    public function getSupportedVersions(): array
    {
        return self::SUPPORTED_VERSIONS;
    }

    /**
     * 获取支持的模型列表
     */
    public function getSupportedModels(): array
    {
        return self::SUPPORTED_MODELS;
    }

    /**
     * 解析模型名称
     *
     * 解析优先级：options['model'] 显式指定 > version + tier 组合
     * 例如 version=2.5, tier=pro => seedance-2.5-pro
     */
    private function resolveModel(array $options): string
    {
        if (isset($options['model']) && is_string($options['model']) && $options['model'] !== '') {
            return in_array($options['model'], self::SUPPORTED_MODELS, true)
                ? $options['model']
                : self::DEFAULT_MODEL;
        }

        $version = $options['version'] ?? '2.0';
        $tier = $options['tier'] ?? 'pro';

        return self::VERSION_MODELS[$version][$tier] ?? self::DEFAULT_MODEL;
    }

    private function resolveResolution(string $resolution): string
    {
        $resolution = strtolower($resolution);

        if (!in_array($resolution, self::SUPPORTED_RESOLUTIONS, true)) {
            return self::DEFAULT_RESOLUTION;
        }

        return $resolution;
    }

    private function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw ConfigurationException::missing('api_key');
        }
    }

    private function validatePrompt(string $prompt): void
    {
        if (strlen($prompt) > 5000) {
            throw InvalidInputException::invalidParameter('prompt', '长度不能超过 5000 字符');
        }
    }

    private function validateImageInput(string $image): void
    {
        if (empty($image)) {
            throw InvalidInputException::invalidParameter('image', '图像不能为空');
        }
    }

    private function resolveImageUrl(string $image): string
    {
        if (str_starts_with($image, 'data:image/')) {
            return $image;
        }

        if (filter_var($image, FILTER_VALIDATE_URL)) {
            return $image;
        }

        if (file_exists($image)) {
            $content = file_get_contents($image);
            $mime = mime_content_type($image);
            return 'data:' . $mime . ';base64,' . base64_encode($content);
        }

        return $image;
    }

    private function normalizeOptions(array $options): array
    {
        $aspectRatio = $options['aspect_ratio'] ?? '16:9';
        if (!isset(self::ASPECT_RATIOS[$aspectRatio])) {
            $aspectRatio = '16:9';
        }
        $options['aspect_ratio'] = $aspectRatio;

        return $options;
    }

    private function getBaseUrl(): string
    {
        return $this->config['base_url'] ?? self::BASE_URL;
    }

    private function getVideoEndpoint(): string
    {
        return $this->getBaseUrl() . 'video/generations';
    }

    private function sendChatRequest(PromptInterface $prompt, array $options): array
    {
        $body = $this->buildChatBody($prompt, $options);

        $request = new Request('POST', $this->getBaseUrl() . 'chat/completions', [
            'Authorization' => 'Bearer ' . $this->config['api_key'],
            'Content-Type' => 'application/json',
        ], json_encode($body));

        $response = $this->client->sendRequest($request);
        return json_decode($response->getBody()->getContents(), true);
    }

    private function buildChatBody(PromptInterface $prompt, array $options): array
    {
        return [
            'model' => $options['model'] ?? self::DEFAULT_MODEL,
            'messages' => [
                ['role' => 'user', 'content' => $prompt->text()],
            ],
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 2000,
            'stream' => $options['stream'] ?? false,
        ];
    }
}
