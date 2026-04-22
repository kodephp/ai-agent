<?php

declare(strict_types=1);

namespace Kode\AiAgent\Video;

use Kode\AiAgent\Domain\Model\VideoResponse;
use Kode\AiAgent\Infrastructure\Adapter\SeedanceAdapter;
use Kode\AiAgent\Log\LogManager;

/**
 * Seedance 视频服务
 *
 * 直接调用 Seedance 2.0 API 生成视频，无需通过 Agent 代理。
 * 提供简洁的接口，支持 720P/1080P 分辨率配置。
 *
 * @package Kode\AiAgent\Video
 *
 * @example
 * ```php
 * $service = new SeedanceService('your-api-key');
 *
 * // 文生视频 (默认 720P)
 * $result = $service->textToVideo('一只猫咪在草地上玩耍');
 *
 * // 文生视频 (1080P)
 * $result = $service->textToVideo('一只猫咪在草地上玩耍', [
 *     'resolution' => '1080p',
 * ]);
 *
 * // 图生视频
 * $result = $service->imageToVideo('/path/to/image.jpg', '让猫咪动起来');
 *
 * // 多镜头视频
 * $result = $service->multiShot('日出风景', 3);
 * ```
 */
class SeedanceService
{
    private SeedanceAdapter $adapter;
    private array $defaultOptions;
    private ?object $logger;

    public function __construct(
        string $apiKey,
        array $options = [],
        ?object $logger = null,
    ) {
        $this->adapter = SeedanceAdapter::create($apiKey, [
            'timeout' => $options['timeout'] ?? 120,
            'retries' => $options['retries'] ?? 3,
        ]);

        $this->defaultOptions = [
            'resolution' => $options['resolution'] ?? '720p',
            'duration' => $options['duration'] ?? 10,
            'aspect_ratio' => $options['aspect_ratio'] ?? '16:9',
            'fps' => $options['fps'] ?? 30,
        ];

        $this->logger = $logger ?? LogManager::channel('seedance');
    }

    public static function create(string $apiKey, array $options = []): self
    {
        return new self($apiKey, $options);
    }

    /**
     * 文生视频
     *
     * @param string $prompt 提示词
     * @param array{
     *     resolution?: '720p'|'1080p',
     *     duration?: int,
     *     aspect_ratio?: string,
     *     fps?: int,
     *     negative_prompt?: string,
     *     seed?: int,
     * } $options 选项
     * @return VideoResponse 视频响应
     *
     * @example
     * ```php
     * // 720P 高清
     * $video = $service->textToVideo('一只可爱的猫咪');
     *
     * // 1080P 全高清
     * $video = $service->textToVideo('一只可爱的猫咪', [
     *     'resolution' => '1080p',
     * ]);
     *
     * // 纵向视频
     * $video = $service->textToVideo('舞蹈表演', [
     *     'aspect_ratio' => '9:16',
     * ]);
     * ```
     */
    public function textToVideo(string $prompt, array $options = []): VideoResponse
    {
        $this->logger?->info('文生视频开始', [
            'prompt' => substr($prompt, 0, 50),
            'resolution' => $options['resolution'] ?? $this->defaultOptions['resolution'],
        ]);

        $options = $this->mergeOptions($options);

        try {
            $result = $this->adapter->generateVideo($prompt, $options);

            $this->logger?->info('文生视频完成', [
                'video_url' => $result->firstVideo(),
                'duration' => $result->duration(),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger?->error('文生视频失败', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 图生视频
     *
     * @param string $image 图像 (URL 或本地路径)
     * @param string|null $prompt 提示词 (可选)
     * @param array{
     *     resolution?: '720p'|'1080p',
     *     duration?: int,
     *     aspect_ratio?: string,
     *     fps?: int,
     * } $options 选项
     * @return VideoResponse 视频响应
     *
     * @example
     * ```php
     * // 从 URL 生成
     * $video = $service->imageToVideo('https://example.com/image.jpg');
     *
     * // 从本地文件生成
     * $video = $service->imageToVideo('/path/to/image.jpg', '让场景动起来');
     *
     * // 1080P 纵向视频
     * $video = $service->imageToVideo('image.jpg', null, [
     *     'resolution' => '1080p',
     *     'aspect_ratio' => '9:16',
     * ]);
     * ```
     */
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
    {
        $this->logger?->info('图生视频开始', [
            'image' => is_url($image) ? $image : basename($image),
            'has_prompt' => $prompt !== null,
            'resolution' => $options['resolution'] ?? $this->defaultOptions['resolution'],
        ]);

        $options = $this->mergeOptions($options);

        try {
            $result = $this->adapter->imageToVideo($image, $prompt, $options);

            $this->logger?->info('图生视频完成', [
                'video_url' => $result->firstVideo(),
                'duration' => $result->duration(),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger?->error('图生视频失败', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 多镜头视频
     *
     * @param string $prompt 提示词
     * @param int $shots 镜头数量 (2-6)
     * @param array{
     *     resolution?: '720p'|'1080p',
     *     duration?: int,
     *     aspect_ratio?: string,
     * } $options 选项
     * @return VideoResponse 视频响应
     *
     * @example
     * ```php
     * // 生成 3 个镜头
     * $video = $service->multiShot('日出风景', 3);
     *
     * // 生成 5 个镜头，1080P
     * $video = $service->multiShot('海边日落', 5, [
     *     'resolution' => '1080p',
     * ]);
     * ```
     */
    public function multiShot(string $prompt, int $shots = 3, array $options = []): VideoResponse
    {
        $this->logger?->info('多镜头视频开始', [
            'prompt' => substr($prompt, 0, 50),
            'shots' => $shots,
            'resolution' => $options['resolution'] ?? $this->defaultOptions['resolution'],
        ]);

        $options = $this->mergeOptions($options);

        try {
            $result = $this->adapter->generateMultiShot($prompt, $shots, $options);

            $this->logger?->info('多镜头视频完成', [
                'video_count' => count($result->videos()),
                'duration' => $result->duration(),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger?->error('多镜头视频失败', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 获取任务状态
     *
     * @param string $taskId 任务 ID
     * @return array 任务状态
     *
     * @example
     * ```php
     * $status = $service->getStatus('task-xxx-123');
     * if ($status['status'] === 'completed') {
     *     echo "视频已生成: " . $status['video_url'];
     * }
     * ```
     */
    public function getStatus(string $taskId): array
    {
        return $this->adapter->getTaskStatus($taskId);
    }

    /**
     * 等待任务完成
     *
     * @param string $taskId 任务 ID
     * @param int $timeout 超时时间 (秒)
     * @param int $interval 轮询间隔 (秒)
     * @return VideoResponse 视频响应
     *
     * @example
     * ```php
     * $video = $service->waitForCompletion('task-xxx-123', 120, 3);
     * ```
     */
    public function waitForCompletion(string $taskId, int $timeout = 120, int $interval = 3): VideoResponse
    {
        $startTime = time();

        while (time() - $startTime < $timeout) {
            $status = $this->getStatus($taskId);

            if (($status['status'] ?? '') === 'completed') {
                return new VideoResponse(
                    videos: [$status['video_url'] ?? ''],
                    videoDuration: $status['duration'] ?? 0,
                );
            }

            if (($status['status'] ?? '') === 'failed') {
                throw new \RuntimeException('视频生成失败: ' . ($status['error'] ?? '未知错误'));
            }

            sleep($interval);
        }

        throw new \RuntimeException('视频生成超时');
    }

    /**
     * 设置默认分辨率
     *
     * @param string $resolution '720p' 或 '1080p'
     * @return self
     */
    public function setResolution(string $resolution): self
    {
        $this->defaultOptions['resolution'] = $resolution;
        return $this;
    }

    /**
     * 设置默认画幅比例
     *
     * @param string $aspectRatio 画幅比例
     * @return self
     */
    public function setAspectRatio(string $aspectRatio): self
    {
        $this->defaultOptions['aspect_ratio'] = $aspectRatio;
        return $this;
    }

    /**
     * 获取支持的分辨率
     */
    public function getSupportedResolutions(): array
    {
        return SeedanceAdapter::SUPPORTED_RESOLUTIONS;
    }

    /**
     * 获取支持的画幅比例
     */
    public function getSupportedAspectRatios(): array
    {
        return array_keys(SeedanceAdapter::ASPECT_RATIOS);
    }

    private function mergeOptions(array $options): array
    {
        return array_merge($this->defaultOptions, $options);
    }
}

/**
 * 判断是否为 URL
 */
function is_url(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_URL) !== false;
}
