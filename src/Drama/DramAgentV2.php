<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

use Kode\AiAgent\Domain\Contract\MultimodalInterface;
use Kode\AiAgent\Domain\Model\{ImageResponse, VideoResponse};
use Kode\AiAgent\Agent\{AgentMemory, CostTracker, ExecutionContext};
use Kode\AiAgent\Async\ParallelExecutor;
use Kode\AiAgent\Log\{LogManager, LoggerFactory};
use Kode\AiAgent\Video\VideoComposerV3;
use Psr\Log\LoggerInterface;

/**
 * 短剧智能体 V2
 *
 * 增强版短剧生成器，支持：
 * - 参考图/参考视频引导生成
 * - 智能场景拆分
 * - 转场效果自动添加
 * - 开场/结尾视频
 * - 背景音乐
 * - 并行处理
 *
 * @package Kode\AiAgent\Drama
 *
 * @example
 * ```php
 * $agent = new DramAgentV2($adapter);
 *
 * // 一键生成完整短剧
 * $result = $agent->generate('在一个阳光明媚的早晨...', [
 *     'scenes' => 5,
 *     'opening' => ['title' => '精彩故事'],
 *     'closing' => ['text' => '感谢观看'],
 *     'transition' => 'fade',
 *     'background_music' => '/path/to/music.mp3',
 * ]);
 *
 * echo $result->video;
 * ```
 */
final class DramAgentV2
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
            'transition_type' => 'fade',
            'transition_duration' => 1,
            'enable_parallel' => true,
            'concurrency' => 4,
            'output_dir' => 'var/drama/output',
            'opening' => null,
            'closing' => null,
            'background_music' => null,
            'max_image_size' => '1024x1024',
        ], $config);
    }

    /**
     * 一键生成完整短剧
     */
    public function generate(string $script, array $options = []): DramaResultV2
    {
        $context = new ExecutionContext(
            id: $this->generateId(),
            task: $script,
            role: 'dram_agent_v2',
            options: array_merge($this->config, $options),
        );

        $context->start();
        $this->log('info', 'DramAgentV2 开始生成短剧', ['script' => substr($script, 0, 100)]);

        $startTime = microtime(true);

        try {
            $storyBoard = $this->parseScript($script, $options);
            $context->addArtifact('storyboard_id', $storyBoard->id);
            $this->log('info', '剧本解析完成', ['scenes' => count($storyBoard->scenes)]);

            $scenes = $this->generateEnhancedScenes($storyBoard, $options);
            $context->addArtifact('scenes_generated', count($scenes));

            $sceneVideos = $this->generateSceneVideos($scenes, $options);
            $context->addArtifact('videos_generated', count($sceneVideos));

            $composer = $this->createComposer($sceneVideos, $options);
            $result = $composer->compose($options);

            $duration = microtime(true) - $startTime;
            $context->complete([
                'video' => $result['output'],
                'duration' => $duration,
            ]);

            $this->log('info', '短剧生成完成', [
                'total_duration' => round($duration, 2),
                'scenes' => count($storyBoard->scenes),
                'output' => $result['output'],
            ]);

            return new DramaResultV2(
                id: $context->id(),
                video: $result['output'],
                storyBoard: $storyBoard,
                scenes: $scenes,
                sceneVideos: $sceneVideos,
                duration: $duration,
                metadata: [
                    'total_duration' => $result['total_duration'] ?? 0,
                    'transitions_count' => $result['transitions_count'] ?? 0,
                ],
            );
        } catch (\Throwable $e) {
            $context->fail($e->getMessage());
            $this->log('error', '短剧生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * 解析剧本生成增强场景
     */
    public function parseScript(string $script, array $options = []): StoryBoardV2
    {
        $sceneCount = $options['scenes'] ?? $this->config['scenes'];
        $style = $options['style'] ?? $this->config['style'];
        $transitionType = $options['transition_type'] ?? $this->config['transition_type'];

        $scriptParts = $this->splitScript($script, $sceneCount);

        $scenes = [];
        $transitionManager = new TransitionManager();

        foreach ($scriptParts as $index => $part) {
            $order = $index + 1;
            $sceneId = "scene-{$order}";

            $sceneOptions = $part['options'] ?? [];

            $scene = new EnhancedScene(
                id: $sceneId,
                order: $order,
                description: $part['description'],
                type: SceneType::MAIN,
                style: $style,
                duration: $sceneOptions['duration'] ?? $this->config['duration_per_scene'],
                metadata: [
                    'original_text' => $part['original'],
                    'total_scenes' => $sceneCount,
                    'transition' => $transitionType,
                ],
                referenceImage: $sceneOptions['reference_image'] ?? $options['reference_image'] ?? null,
                referenceVideo: $sceneOptions['reference_video'] ?? $options['reference_video'] ?? null,
                transitionEffect: $transitionType,
                transitionDuration: $this->config['transition_duration'],
            );

            $scenes[] = $scene;

            if ($index > 0) {
                $prevSceneId = "scene-{$index}";
                $transitionManager->addTransition(
                    $prevSceneId,
                    $sceneId,
                    $this->parseTransitionType($transitionType),
                    $this->config['transition_duration']
                );
            }
        }

        $storyBoard = new StoryBoardV2(
            id: $this->generateId(),
            title: $options['title'] ?? '短剧',
            script: $script,
            scenes: $scenes,
            style: $style,
            transitionManager: $transitionManager,
            metadata: $options['metadata'] ?? [],
        );

        if ($options['reference_image'] ?? null) {
            $storyBoard = $storyBoard->withReferenceImage($options['reference_image']);
        }

        if ($options['reference_video'] ?? null) {
            $storyBoard = $storyBoard->withReferenceVideo($options['reference_video']);
        }

        return $storyBoard;
    }

    /**
     * 生成增强场景
     */
    public function generateEnhancedScenes(StoryBoardV2 $storyBoard, array $options = []): array
    {
        $this->log('info', '开始生成增强场景', ['count' => count($storyBoard->scenes)]);

        $tasks = [];

        foreach ($storyBoard->scenes as $scene) {
            $tasks[] = fn() => $this->generateSingleEnhancedScene($scene, $options, $storyBoard);
        }

        if ($this->config['enable_parallel'] && count($tasks) > 1) {
            $results = $this->executor->executeBatch($tasks);
        } else {
            $results = array_map(fn($task) => $task(), $tasks);
        }

        return $results;
    }

    /**
     * 生成单个增强场景
     */
    private function generateSingleEnhancedScene(EnhancedScene $scene, array $options, StoryBoardV2 $storyBoard): EnhancedScene
    {
        $this->log('debug', "生成场景 {$scene->order}", [
            'description' => substr($scene->description, 0, 50),
            'has_reference' => $scene->hasReferenceImage() || $scene->hasReferenceVideo(),
        ]);

        $imagePrompt = $scene->description;

        if ($storyBoard->referenceImage !== null && !$scene->hasReferenceImage()) {
            $imagePrompt .= ' [风格参考: ' . $storyBoard->referenceImage . ']';
        }

        $imageOptions = [
            'style' => $scene->style,
            'size' => $this->config['image_size'],
        ];

        if ($scene->hasReferenceImage()) {
            $imageOptions['reference_image'] = $scene->referenceImage;
        }

        $imageResponse = $this->adapter->generateImage($imagePrompt, $imageOptions);

        $scene = $scene->withImage($imageResponse->firstImage());

        return $scene;
    }

    /**
     * 生成场景视频
     */
    public function generateSceneVideos(array $scenes, array $options = []): array
    {
        $this->log('info', '开始生成场景视频', ['count' => count($scenes)]);

        $videos = [];

        foreach ($scenes as $scene) {
            if ($scene->imageUrl === null) {
                $this->log('warning', "场景 {$scene->id} 无图像，跳过视频生成");
                continue;
            }

            $videoOptions = [
                'duration' => $scene->duration,
                'resolution' => $options['video_resolution'] ?? $this->config['video_resolution'],
            ];

            if ($scene->hasReferenceVideo()) {
                $videoOptions['reference_video'] = $scene->referenceVideo;
            }

            $videoResponse = $this->adapter->imageToVideo(
                $scene->imageUrl,
                $scene->description,
                $videoOptions
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

    /**
     * 创建视频合成器
     */
    private function createComposer(array $sceneVideos, array $options): VideoComposerV3
    {
        $composer = new VideoComposerV3(
            $this->logger,
            $this->config['concurrency'],
            ['output_dir' => $options['output_dir'] ?? $this->config['output_dir']]
        );

        $composer->addSceneVideos($sceneVideos);

        if ($options['opening'] ?? $this->config['opening']) {
            $openingConfig = is_array($options['opening'] ?? $this->config['opening'])
                ? ($options['opening'] ?? $this->config['opening'])
                : [];

            $opening = FrameVideo::opening(
                $openingConfig['url'] ?? $this->generateFrameVideoUrl('opening'),
                [
                    'duration' => $openingConfig['duration'] ?? 5,
                    'title' => $openingConfig['title'] ?? '精彩故事即将开始',
                ]
            );

            $composer->setOpening($opening);
        }

        if ($options['closing'] ?? $this->config['closing']) {
            $closingConfig = is_array($options['closing'] ?? $this->config['closing'])
                ? ($options['closing'] ?? $this->config['closing'])
                : [];

            $closing = FrameVideo::closing(
                $closingConfig['url'] ?? $this->generateFrameVideoUrl('closing'),
                [
                    'duration' => $closingConfig['duration'] ?? 10,
                    'ending_text' => $closingConfig['text'] ?? '感谢观看',
                ]
            );

            $composer->setClosing($closing);
        }

        if ($options['background_music'] ?? $this->config['background_music']) {
            $composer->setBackgroundMusic(
                $options['background_music'] ?? $this->config['background_music'],
                $options['music_volume'] ?? 0.3
            );
        }

        return $composer;
    }

    /**
     * 分割剧本
     */
    private function splitScript(string $script, int $sceneCount): array
    {
        $sentences = preg_split('/[。！？\n]+/', $script);
        $sentences = array_filter($sentences, fn($s) => trim($s) !== '');

        if (count($sentences) <= $sceneCount) {
            return array_map(fn($s, $i) => [
                'original' => $s,
                'description' => $this->enhanceDescription($s),
                'options' => [],
            ], $sentences, array_keys($sentences));
        }

        $parts = array_chunk($sentences, (int) ceil(count($sentences) / $sceneCount));

        return array_map(function ($part, $index) use ($sceneCount) {
            $text = implode('。', $part);
            return [
                'original' => $text,
                'description' => $this->enhanceDescription($text),
                'options' => [],
            ];
        }, array_slice($parts, 0, $sceneCount), array_keys($parts));
    }

    /**
     * 增强描述
     */
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

    /**
     * 解析转场类型
     */
    private function parseTransitionType(string $type): TransitionType
    {
        return match (strtolower($type)) {
            'fade' => TransitionType::FADE,
            'dissolve' => TransitionType::DISSOLVE,
            'slide_left' => TransitionType::SLIDE_LEFT,
            'slide_right' => TransitionType::SLIDE_RIGHT,
            'slide_up' => TransitionType::SLIDE_UP,
            'slide_down' => TransitionType::SLIDE_DOWN,
            'zoom_in' => TransitionType::ZOOM_IN,
            'zoom_out' => TransitionType::ZOOM_OUT,
            'blur' => TransitionType::BLUR,
            'cross_wipe' => TransitionType::CROSS_WIPE,
            default => TransitionType::FADE,
        };
    }

    /**
     * 生成帧视频 URL
     */
    private function generateFrameVideoUrl(string $type): string
    {
        return sprintf(
            'https://cdn.example.com/frames/%s_%s.mp4',
            $type,
            date('Ymd-His')
        );
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
        $logMessage = "[DramAgentV2] {$message}";

        if ($this->logger !== null) {
            $this->logger->$level($logMessage, $context);
        }

        LogManager::channel('drama')->$level($message, $context);
    }
}