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
 * // 批量生成
 * $results = $service->batchTextToVideo([
 *     '一只可爱的猫咪',
 *     '日出风景',
 *     '海浪拍岸',
 * ]);
 *
 * // 并发生成
 * $results = $service->parallelTextToVideo([
 *     '猫咪在玩耍',
 *     '狗狗在奔跑',
 *     '鸟儿在飞翔',
 * ], concurrency: 3);
 * ```
 */
final class SeedanceService
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

    public function batchTextToVideo(array $prompts, array $options = []): array
    {
        $this->logger?->info('批量文生视频开始', [
            'count' => count($prompts),
        ]);

        $results = [];
        foreach ($prompts as $index => $prompt) {
            try {
                $results[$index] = [
                    'success' => true,
                    'prompt' => $prompt,
                    'response' => $this->textToVideo($prompt, $options),
                ];
            } catch (\Throwable $e) {
                $results[$index] = [
                    'success' => false,
                    'prompt' => $prompt,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $this->logger?->info('批量文生视频完成', [
            'total' => count($prompts),
            'success' => $successCount,
            'failed' => count($prompts) - $successCount,
        ]);

        return $results;
    }

    public function parallelTextToVideo(array $prompts, array $options = [], int $concurrency = 3): array
    {
        $this->logger?->info('并发文生视频开始', [
            'count' => count($prompts),
            'concurrency' => $concurrency,
        ]);

        $results = [];
        $chunks = array_chunk($prompts, $concurrency);

        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $index => $prompt) {
                $actualIndex = $chunkIndex * $concurrency + $index;
                try {
                    $results[$actualIndex] = [
                        'success' => true,
                        'prompt' => $prompt,
                        'response' => $this->textToVideo($prompt, $options),
                    ];
                } catch (\Throwable $e) {
                    $results[$actualIndex] = [
                        'success' => false,
                        'prompt' => $prompt,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $this->logger?->info('并发文生视频完成', [
            'total' => count($prompts),
            'success' => $successCount,
            'failed' => count($prompts) - $successCount,
        ]);

        return $results;
    }

    public function batchImageToVideo(array $images, array $options = []): array
    {
        $this->logger?->info('批量图生视频开始', [
            'count' => count($images),
        ]);

        $results = [];
        foreach ($images as $index => $item) {
            $image = is_array($item) ? ($item['image'] ?? $item[0] ?? '') : $item;
            $prompt = is_array($item) ? ($item['prompt'] ?? $item[1] ?? null) : null;

            try {
                $results[$index] = [
                    'success' => true,
                    'image' => $image,
                    'prompt' => $prompt,
                    'response' => $this->imageToVideo($image, $prompt, $options),
                ];
            } catch (\Throwable $e) {
                $results[$index] = [
                    'success' => false,
                    'image' => $image,
                    'prompt' => $prompt,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $this->logger?->info('批量图生视频完成', [
            'total' => count($images),
            'success' => $successCount,
            'failed' => count($images) - $successCount,
        ]);

        return $results;
    }

    public function getStatus(string $taskId): array
    {
        return $this->adapter->getTaskStatus($taskId);
    }

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

    public function setResolution(string $resolution): self
    {
        $this->defaultOptions['resolution'] = $resolution;
        return $this;
    }

    public function setAspectRatio(string $aspectRatio): self
    {
        $this->defaultOptions['aspect_ratio'] = $aspectRatio;
        return $this;
    }

    public function setDuration(int $duration): self
    {
        $this->defaultOptions['duration'] = $duration;
        return $this;
    }

    public function getSupportedResolutions(): array
    {
        return SeedanceAdapter::SUPPORTED_RESOLUTIONS;
    }

    public function getSupportedAspectRatios(): array
    {
        return array_keys(SeedanceAdapter::ASPECT_RATIOS);
    }

    public function getAdapter(): SeedanceAdapter
    {
        return $this->adapter;
    }

    private function mergeOptions(array $options): array
    {
        return array_merge($this->defaultOptions, $options);
    }
}

function is_url(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_URL) !== false;
}
