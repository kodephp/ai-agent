<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

use Kode\AiAgent\Domain\Contract\MultimodalInterface;
use Kode\AiAgent\Domain\Model\{ImageResponse, VideoResponse};
use Kode\AiAgent\Agent\{AgentMemory, CostTracker, ExecutionContext};
use Kode\AiAgent\Async\ParallelExecutor;
use Kode\AiAgent\Log\{LogManager, LoggerFactory};
use Kode\AiAgent\Video\VideoComposerV2;
use Psr\Log\LoggerInterface;

final class DramAgent
{
    private MultimodalInterface $adapter;
    private ?LoggerInterface $logger;
    private ?AgentMemory $memory;
    private ?CostTracker $costTracker;
    private ParallelExecutor $executor;
    private array $config;

    public function __construct(
        MultimodalInterface $adapter,
        array $config = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->adapter = $adapter;
        $this->logger = $logger;
        $this->memory = $config['memory'] ?? null;
        $this->costTracker = $config['cost_tracker'] ?? null;
        $this->executor = new ParallelExecutor(
            $config['concurrency'] ?? 4,
            $config['enable_parallel'] ?? true
        );
        $this->config = array_merge([
            'scenes' => 5,
            'duration_per_scene' => 10,
            'style' => 'cinematic',
            'image_size' => '1920x1080',
            'video_resolution' => '1080p',
            'transition' => 'fade',
            'enable_parallel' => true,
            'concurrency' => 4,
            'output_dir' => 'var/drama/output',
        ], $config);
    }

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

    public function withCostTracking(?CostTracker $tracker = null): self
    {
        $this->costTracker = $tracker ?? new CostTracker();
        return $this;
    }

    public function generate(string $script, array $options = []): DramaResult
    {
        $context = new ExecutionContext(
            id: $this->generateId(),
            task: $script,
            role: 'dram_agent',
            options: array_merge($this->config, $options),
        );

        $context->start();
        $this->log('info', 'DramAgent 开始生成短剧', ['script' => substr($script, 0, 100)]);

        $startTime = microtime(true);

        try {
            $storyBoard = $this->parseScript($script, $options);
            $context->addArtifact('storyboard_id', $storyBoard->id);
            $this->log('info', '剧本解析完成', ['scenes' => count($storyBoard->scenes)]);

            $scenesWithImages = $this->generateSceneImages($storyBoard, $options);
            $context->addArtifact('images_generated', count($scenesWithImages));
            $this->log('info', '场景图像生成完成', ['count' => count($scenesWithImages)]);

            $sceneVideos = $this->generateSceneVideos($scenesWithImages, $options);
            $context->addArtifact('videos_generated', count($sceneVideos));
            $this->log('info', '场景视频生成完成', ['count' => count($sceneVideos)]);

            $finalVideo = $this->composeFinalVideo($sceneVideos, $options);
            $context->addArtifact('final_video', $finalVideo);
            $this->log('info', '视频合成完成', ['output' => $finalVideo]);

            $duration = microtime(true) - $startTime;
            $context->complete(['video' => $finalVideo, 'duration' => $duration]);

            $this->log('info', '短剧生成全部完成', [
                'total_duration' => round($duration, 2),
                'scenes' => count($storyBoard->scenes),
            ]);

            if ($this->memory !== null) {
                $this->memory->remember("drama_{$context->id()}", [
                    'script' => $script,
                    'result' => $finalVideo,
                    'duration' => $duration,
                ], 'dram_agent');
            }

            return new DramaResult(
                id: $context->id(),
                video: $finalVideo,
                scenes: $scenesWithImages,
                duration: $duration,
            );
        } catch (\Throwable $e) {
            $context->fail($e->getMessage());
            $this->log('error', '短剧生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function parseScript(string $script, array $options = []): StoryBoard
    {
        $sceneCount = $options['scenes'] ?? $this->config['scenes'];
        $style = $options['style'] ?? $this->config['style'];

        $scriptParts = $this->splitScript($script, $sceneCount);

        $scenes = [];

        foreach ($scriptParts as $index => $part) {
            $order = $index + 1;
            $scenes[] = new Scene(
                id: "scene-{$order}",
                order: $order,
                description: $part['description'],
                style: $style,
                duration: $options['duration_per_scene'] ?? $this->config['duration_per_scene'],
                metadata: [
                    'original_text' => $part['original'],
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

    public function generateSceneImages(StoryBoard $storyBoard, array $options = []): array
    {
        $this->log('info', '开始生成场景图像', ['count' => count($storyBoard->scenes)]);

        $tasks = array_map(
            fn($scene) => fn() => $this->generateSingleImage($scene, $options),
            $storyBoard->scenes
        );

        if ($this->config['enable_parallel'] && count($tasks) > 1) {
            $results = $this->executor->executeBatch($tasks);
        } else {
            $results = array_map(fn($task) => $task(), $tasks);
        }

        return $results;
    }

    public function generateSceneVideos(array $scenes, array $options = []): array
    {
        $this->log('info', '开始生成场景视频', ['count' => count($scenes)]);

        $videos = [];

        foreach ($scenes as $scene) {
            if ($scene->imageUrl === null) {
                $this->log('warning', "场景 {$scene->id} 无图像，跳过视频生成");
                continue;
            }

            $videoResponse = $this->adapter->imageToVideo(
                $scene->imageUrl,
                $scene->description,
                [
                    'duration' => $scene->duration,
                    'resolution' => $options['video_resolution'] ?? $this->config['video_resolution'],
                ]
            );

            $videos[] = new SceneVideo(
                sceneId: $scene->id,
                order: $scene->order,
                videoUrl: $videoResponse->firstVideo(),
                duration: $videoResponse->videoDuration(),
                imageUrl: $scene->imageUrl,
            );
        }

        return $videos;
    }

    public function composeFinalVideo(array $sceneVideos, array $options = []): string
    {
        $this->log('info', '开始合成最终视频', ['count' => count($sceneVideos)]);

        $composer = new VideoComposerV2(
            $this->logger,
            $this->config['concurrency'],
            ['output_dir' => $options['output_dir'] ?? $this->config['output_dir']]
        );

        return $composer->compose($sceneVideos, [
            'transition' => $options['transition'] ?? $this->config['transition'],
            'background_music' => $options['background_music'] ?? null,
            'music_volume' => $options['music_volume'] ?? 0.3,
        ]);
    }

    public function generateWithAvatar(string $script, array $avatarOptions = [], array $dramaOptions = []): DramaResult
    {
        $context = new ExecutionContext(
            id: $this->generateId(),
            task: $script,
            role: 'dram_agent_with_avatar',
            options: $dramaOptions,
        );

        $context->start();
        $this->log('info', '开始生成数字人短剧');

        try {
            $storyBoard = $this->parseScript($script, $dramaOptions);

            $scenesWithImages = $this->generateSceneImages($storyBoard, $dramaOptions);

            $avatarVideos = $this->generateAvatarVideos($storyBoard, $avatarOptions, $dramaOptions);

            $allVideos = array_merge($scenesWithImages, $avatarVideos);

            usort($allVideos, fn($a, $b) => $a->order <=> $b->order);

            $finalVideo = $this->composeFinalVideo($allVideos, $dramaOptions);

            $context->complete(['video' => $finalVideo]);

            return new DramaResult(
                id: $context->id(),
                video: $finalVideo,
                scenes: $scenesWithImages,
                duration: $context->duration(),
            );
        } catch (\Throwable $e) {
            $context->fail($e->getMessage());
            throw $e;
        }
    }

    private function generateSingleImage(Scene $scene, array $options): Scene
    {
        $this->log('debug', "生成场景 {$scene->order} 图像", ['description' => substr($scene->description, 0, 50)]);

        $imageResponse = $this->adapter->generateImage($scene->description, [
                'style' => $scene->style,
                'size' => $options['image_size'] ?? $this->config['image_size'],
            ]);

            $scene = $scene->withImage($imageResponse->firstImage());

        if ($this->memory !== null) {
            $this->memory->remember("scene_image_{$scene->id}", $scene->toArray(), 'dram_agent');
        }

        return $scene;
    }

    private function generateAvatarVideos(StoryBoard $storyBoard, array $avatarOptions, array $dramaOptions): array
    {
        $videos = [];

        foreach ($storyBoard->scenes as $scene) {
            $avatarResponse = $this->adapter->generateAvatarVideo(
                $avatarOptions['avatar_id'] ?? 'default',
                $scene->description,
                [
                    'duration' => $scene->duration,
                    'language' => $avatarOptions['language'] ?? 'zh-CN',
                ]
            );

            $videos[] = new SceneVideo(
                sceneId: $scene->id . '_avatar',
                order: $scene->order + 100,
                videoUrl: $avatarResponse->video(),
                duration: $avatarResponse->videoDuration(),
                imageUrl: null,
            );
        }

        return $videos;
    }

    private function splitScript(string $script, int $sceneCount): array
    {
        $sentences = preg_split('/[。！？\n]+/', $script);
        $sentences = array_filter($sentences, fn($s) => trim($s) !== '');

        if (count($sentences) <= $sceneCount) {
            return array_map(fn($s, $i) => [
                'original' => $s,
                'description' => $this->enhanceDescription($s),
            ], $sentences, array_keys($sentences));
        }

        $parts = array_chunk($sentences, (int) ceil(count($sentences) / $sceneCount));

        return array_map(function ($part, $index) use ($sceneCount) {
            $text = implode('。', $part);
            return [
                'original' => $text,
                'description' => $this->enhanceDescription($text),
            ];
        }, array_slice($parts, 0, $sceneCount), array_keys($parts));
    }

    private function enhanceDescription(string $text): string
    {
        $enhancements = [
            '风格' => '电影感、光线柔和、色彩温暖',
            '镜头' => '中景到特写切换',
            '氛围' => '情感丰富、节奏明快',
        ];

        $enhanced = $text;

        foreach ($enhancements as $key => $value) {
            if (!str_contains($enhanced, $key) && !str_contains($enhanced, $value)) {
                $enhanced .= '，' . $value;
            }
        }

        return $enhanced;
    }

    private function generateId(): string
    {
        return sprintf('drama-%s-%s', date('Ymd-His'), bin2hex(random_bytes(4)));
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $logMessage = "[DramAgent] {$message}";

        if ($this->logger !== null) {
            $this->logger->$level($logMessage, $context);
        }

        LogManager::channel('drama')->$level($message, $context);
    }
}