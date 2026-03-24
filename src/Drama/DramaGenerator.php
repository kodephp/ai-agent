<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

use Kode\AiAgent\Domain\Contract\MultimodalInterface;
use Kode\AiAgent\Domain\Model\{ImageResponse, VideoResponse};
use Kode\AiAgent\Agent\{AgentMemory, CostTracker, ExecutionContext};
use Psr\Log\LoggerInterface;

/**
 * 短剧生成器
 *
 * 一键生成短剧，支持完整的短剧制作流程：
 * 1. 剧本解析和场景拆分
 * 2. 场景图像生成
 * 3. 图像转视频
 * 4. 数字人口播视频
 * 5. 视频合成
 *
 * @package Kode\AiAgent\Drama
 *
 * @example
 * ```php
 * $generator = new DramaGenerator($multimodalAdapter);
 * $result = $generator->generate('一个温馨的家庭故事', [
 *     'scenes' => 5,
 *     'duration_per_scene' => 10,
 *     'style' => 'cinematic',
 * ]);
 * ```
 */
final class DramaGenerator
{
    private ?LoggerInterface $logger = null;
    private ?AgentMemory $memory = null;
    private ?CostTracker $costTracker = null;

    public function __construct(
        private MultimodalInterface $adapter,
    ) {}

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function withMemory(AgentMemory $memory): self
    {
        $this->memory = $memory;
        return $this;
    }

    public function withCostTracking(): self
    {
        $this->costTracker = new CostTracker();
        return $this;
    }

    public function costTracker(): ?CostTracker
    {
        return $this->costTracker;
    }

    /**
     * 生成短剧
     */
    public function generate(string $script, array $options = []): DramaResult
    {
        $context = new ExecutionContext(
            id: $this->generateId(),
            task: $script,
            role: 'drama_generator',
            options: $options,
        );

        $context->start();
        $this->log('info', '开始生成短剧', ['script' => substr($script, 0, 100)]);

        try {
            $storyBoard = $this->parseScript($script, $options);
            $context->addArtifact('storyboard', $storyBoard);

            $scenes = $this->generateScenes($storyBoard, $options);
            $context->addArtifact('scenes', count($scenes));

            $videos = $this->generateVideos($scenes, $options);
            $context->addArtifact('videos', count($videos));

            $finalVideo = $this->composeVideos($videos, $options);
            $context->complete(['video' => $finalVideo]);

            $this->log('info', '短剧生成完成', [
                'duration' => $context->duration(),
                'scenes' => count($scenes),
            ]);

            return new DramaResult(
                id: $context->id(),
                video: $finalVideo,
                storyBoard: $storyBoard,
                scenes: $scenes,
                duration: $context->duration(),
            );
        } catch (\Throwable $e) {
            $context->fail($e->getMessage());
            $this->log('error', '短剧生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * 解析剧本，生成故事板
     */
    public function parseScript(string $script, array $options = []): StoryBoard
    {
        $this->log('info', '解析剧本');

        $sceneCount = $options['scenes'] ?? 3;
        $style = $options['style'] ?? 'cinematic';

        $scenes = [];

        for ($i = 1; $i <= $sceneCount; $i++) {
            $scenes[] = new Scene(
                id: "scene-{$i}",
                order: $i,
                description: "场景 {$i}: " . $this->extractSceneDescription($script, $i, $sceneCount),
                style: $style,
                duration: $options['duration_per_scene'] ?? 10,
                metadata: [
                    'script' => $script,
                    'total_scenes' => $sceneCount,
                ],
            );
        }

        return new StoryBoard(
            id: $this->generateId(),
            title: $options['title'] ?? '短剧',
            script: $script,
            scenes: $scenes,
            style: $style,
            metadata: $options['metadata'] ?? [],
        );
    }

    /**
     * 生成场景图像
     */
    public function generateScenes(StoryBoard $storyBoard, array $options = []): array
    {
        $this->log('info', '生成场景图像', ['scenes' => count($storyBoard->scenes)]);

        $scenes = [];

        foreach ($storyBoard->scenes as $scene) {
            $this->log('info', "生成场景 {$scene->order} 图像");

            $imageResponse = $this->adapter->generateImage($scene->description, [
                'style' => $scene->style,
                'size' => $options['image_size'] ?? '1920x1080',
            ]);

            $scene = $scene->withImage($imageResponse->image());
            $scenes[] = $scene;

            if ($this->memory) {
                $this->memory->remember("scene_{$scene->id}", $scene->toArray(), 'drama_generator');
            }
        }

        return $scenes;
    }

    /**
     * 生成视频
     */
    public function generateVideos(array $scenes, array $options = []): array
    {
        $this->log('info', '生成视频', ['scenes' => count($scenes)]);

        $videos = [];

        foreach ($scenes as $scene) {
            $this->log('info', "生成场景 {$scene->order} 视频");

            if ($scene->imageUrl) {
                $videoResponse = $this->adapter->imageToVideo(
                    $scene->imageUrl,
                    $scene->description,
                    [
                        'duration' => $scene->duration,
                        'resolution' => $options['resolution'] ?? '1080p',
                    ]
                );

                $videos[] = new SceneVideo(
                    sceneId: $scene->id,
                    order: $scene->order,
                    videoUrl: $videoResponse->video(),
                    duration: $videoResponse->videoDuration(),
                    imageUrl: $scene->imageUrl,
                );
            }
        }

        return $videos;
    }

    /**
     * 合成视频
     */
    public function composeVideos(array $videos, array $options = []): string
    {
        $this->log('info', '合成视频', ['videos' => count($videos)]);

        $composer = new VideoComposer($this->logger);

        return $composer->compose($videos, [
            'transition' => $options['transition'] ?? 'fade',
            'background_music' => $options['background_music'] ?? null,
            'output_format' => $options['output_format'] ?? 'mp4',
        ]);
    }

    /**
     * 提取场景描述
     */
    private function extractSceneDescription(string $script, int $sceneNumber, int $totalScenes): string
    {
        $parts = preg_split('/[。！？\n]+/', $script);
        $parts = array_filter($parts);

        if (empty($parts)) {
            return "场景 {$sceneNumber}";
        }

        $partIndex = (int) (($sceneNumber - 1) * count($parts) / $totalScenes);
        $partIndex = min($partIndex, count($parts) - 1);

        return $parts[array_keys($parts)[$partIndex]] ?? "场景 {$sceneNumber}";
    }

    /**
     * 生成唯一 ID
     */
    private function generateId(): string
    {
        return sprintf('drama-%s-%s', date('Ymd-His'), bin2hex(random_bytes(4)));
    }

    /**
     * 日志记录
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->$level("[DramaGenerator] {$message}", $context);
        }
    }
}
