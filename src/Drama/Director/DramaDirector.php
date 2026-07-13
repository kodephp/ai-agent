<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama\Director;

use Kode\AiAgent\Drama\{SceneVideo, TransitionManager, TransitionType};
use Kode\AiAgent\Log\LogManager;
use Kode\AiAgent\Video\VideoComposerV3;
use Kode\AiAgent\VideoGateway\VideoGateway;
use Psr\Log\LoggerInterface;

/**
 * 漫剧导演
 *
 * 以"导演"视角编排 AI 漫剧（短剧）生成：
 * 1. 拆分剧本为分镜（提示词 / 转场 / 背景图 / 背景视频 / 模型绑定）
 * 2. 逐段调用统一视频网关生成视频（每段可绑定不同模型）
 * 3. 支持单段单独调整/重生成（换提示词、换模型、换背景）
 * 4. 按转场合成最终视频
 *
 * 每段通过 ModelBinding 预留模型绑定，后续可直接替换为更优模型
 * （如 Seedance 2.5 → 3.0），无需改动编排逻辑。
 *
 * @package Kode\AiAgent\Drama\Director
 *
 * @example
 * ```php
 * $director = new DramaDirector($videoGateway);
 *
 * // 文本剧本（含行内指令）
 * $script = "场景1：清晨的街道\n@model seedance-2.5-pro\n\n场景2：两人相遇\n@transition dissolve";
 *
 * // 一键生成（自动拆分→生成→合成）
 * $result = $director->generate($script, ['resolution' => '1080p']);
 * echo $result->finalVideo;
 *
 * // 单段重生成（调整第 1 段提示词与模型）
 * $director->regenerateSegment(0, [
 *     'prompt' => '清晨的街道，阳光洒在石板路上',
 *     'model'  => new ModelBinding('seedance', 'seedance-2.5-pro'),
 * ]);
 * $result = $director->compose();
 * ```
 */
final class DramaDirector
{
    private array $segments = [];

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param VideoGateway $gateway 统一视频网关（已配置各供应商）
     * @param array<string, mixed> $config 默认配置
     * @param LoggerInterface|null $logger 日志
     * @param callable|null $composerFactory 合成器工厂（测试注入用；
     *        签名：(?LoggerInterface $logger, int $concurrency, array $config): object，
     *        返回具备 addSceneVideos/addTransition/setBackgroundMusic/compose 方法的对象，
     *        其中 compose() 返回 ['output'=>string, 'total_duration'=>float, 'transitions_count'=>int]。
     *        生产环境传 null，使用默认的 VideoComposerV3（本地 ffmpeg 转场合成）。
     */
    public function __construct(
        private VideoGateway $gateway,
        array $config = [],
    private ?LoggerInterface $logger = null,
    /** @var callable|null */
    private $composerFactory = null,
    ) {
        $this->config = array_merge([
            'resolution' => '1080p',
            'transition_duration' => 1,
            'concurrency' => 4,
            'output_dir' => 'var/drama/output',
            'background_music' => null,
            'default_model' => null,
            'default_transition' => 'fade',
            'default_duration' => 5,
        ], $config);
    }

    /**
     * 一键生成漫剧：拆分 → 逐段生成 → 合成
     *
     * @param string|array<int, array<string, mixed>|string> $script 剧本（文本或结构化数组）
     * @param array<string, mixed> $options 覆盖配置
     */
    public function generate(string|array $script, array $options = []): DramaResult
    {
        $start = microtime(true);

        $this->segments = (new ScriptSplitter())->split(
            $script,
            array_merge($this->config, $options)
        );

        $this->log('info', '漫剧导演：开始生成', ['segments' => count($this->segments)]);

        foreach ($this->segments as $index => $segment) {
            $this->segments[$index] = $this->generateSegmentVideo($segment, $options);
        }

        if ($this->successCount() === 0) {
            $this->log('error', '漫剧导演：所有分镜生成失败', ['segments' => count($this->segments)]);

            return new DramaResult(
                id: $this->generateId(),
                finalVideo: null,
                segments: $this->segments,
                sceneVideos: [],
                duration: round(microtime(true) - $start, 2),
                metadata: [
                    'success_count' => 0,
                    'failed_count' => $this->failedCount(),
                    'segment_count' => count($this->segments),
                ],
            );
        }

        $result = $this->compose($options);

        $this->log('info', '漫剧导演：生成完成', [
            'final' => $result->finalVideo,
            'segments' => $result->segmentCount(),
            'success' => $result->successCount(),
            'failed' => $result->failedCount(),
            'director_duration' => round(microtime(true) - $start, 2),
        ]);

        return $result;
    }

    /**
     * 单段重生成（调整提示词 / 模型 / 背景后重新生成该段视频）
     *
     * 不自动合成，调用方随后调用 compose() 重新合成最终视频。
     *
     * @param int $index 片段序号（从 0 开始）
     * @param array<string, mixed> $options 覆盖：prompt / model / background_image / transition / duration
     * @param string|null $newPrompt 便捷覆盖提示词
     */
    public function regenerateSegment(int $index, array $options = [], ?string $newPrompt = null): DramaSegment
    {
        if (!isset($this->segments[$index])) {
            throw new \OutOfRangeException("片段序号越界：{$index}");
        }

        $segment = $this->segments[$index];

        if ($newPrompt !== null) {
            $segment = $segment->with(['prompt' => $newPrompt]);
        }
        if (isset($options['prompt'])) {
            $segment = $segment->with(['prompt' => $options['prompt']]);
        }
        if (isset($options['background_image'])) {
            $segment = $segment->with(['background_image' => $options['background_image']]);
        }
        if (isset($options['duration'])) {
            $segment = $segment->with(['duration' => (int) $options['duration']]);
        }
        if (isset($options['transition'])) {
            $transition = $options['transition'] instanceof TransitionType
                ? $options['transition']
                : (TransitionType::tryFrom($options['transition']) ?? TransitionType::FADE);
            $segment = $segment->with(['transition' => $transition]);
        }
        if (isset($options['model'])) {
            $binding = $options['model'] instanceof ModelBinding
                ? $options['model']
                : ModelBinding::fromArray($options['model']);
            $segment = $segment->with(['model' => $binding]);
        }

        $updated = $this->generateSegmentVideo($segment, $options);
        $updated = $updated->with(['status' => 'regenerated']);
        $this->segments[$index] = $updated;

        return $updated;
    }

    /**
     * 合成最终视频（基于已生成的分段）
     *
     * 可在 generate() 或 regenerateSegment() 之后调用，重新合成。
     */
    public function compose(array $options = []): DramaResult
    {
        $valid = array_values(array_filter(
            $this->segments,
            static fn(DramaSegment $s) => $s->hasGeneratedVideo()
        ));

        if ($valid === []) {
            throw new \RuntimeException('没有可合成的片段视频');
        }

        $sceneVideos = [];
        $transitionManager = new TransitionManager();
        $transitionDuration = (float) ($options['transition_duration'] ?? $this->config['transition_duration']);

        foreach ($valid as $index => $segment) {
            $sceneVideos[] = new SceneVideo(
                sceneId: $segment->id,
                order: $segment->order,
                videoUrl: $segment->generatedVideo,
                duration: (float) $segment->duration,
                imageUrl: $segment->backgroundImage,
            );

            if ($index < count($valid) - 1) {
                $next = $valid[$index + 1];
                $transitionManager->addTransition(
                    $segment->id,
                    $next->id,
                    $segment->transition,
                    $transitionDuration
                );
            }
        }

        $composer = $this->composerFactory !== null
            ? ($this->composerFactory)($this->logger, (int) ($this->config['concurrency'] ?? 4), ['output_dir' => $options['output_dir'] ?? $this->config['output_dir']])
            : new VideoComposerV3(
                $this->logger,
                $this->config['concurrency'],
                ['output_dir' => $options['output_dir'] ?? $this->config['output_dir']]
            );
        $composer->addSceneVideos($sceneVideos);

        foreach ($valid as $index => $segment) {
            if ($index < count($valid) - 1) {
                $next = $valid[$index + 1];
                $composer->addTransition($segment->id, $next->id, $segment->transition, $transitionDuration);
            }
        }

        if ($options['background_music'] ?? $this->config['background_music']) {
            $composer->setBackgroundMusic(
                $options['background_music'] ?? $this->config['background_music'],
                $options['music_volume'] ?? 0.3
            );
        }

        $result = $composer->compose($options);

        return new DramaResult(
            id: $this->generateId(),
            finalVideo: $result['output'],
            segments: $this->segments,
            sceneVideos: $sceneVideos,
            duration: (float) ($result['total_duration'] ?? 0),
            metadata: [
                'total_duration' => $result['total_duration'] ?? 0,
                'transitions_count' => $result['transitions_count'] ?? 0,
                'segment_count' => count($this->segments),
                'success_count' => $this->successCount(),
                'failed_count' => $this->failedCount(),
                'has_background_music' => ($options['background_music'] ?? $this->config['background_music']) !== null,
            ],
        );
    }

    /**
     * 获取当前所有分镜（含生成结果）
     *
     * @return array<int, DramaSegment>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    /**
     * 生成单个分镜视频
     */
    private function generateSegmentVideo(DramaSegment $segment, array $options): DramaSegment
    {
        // 提供了背景视频：直接复用为背景片段（无需调用生成）
        if ($segment->backgroundVideo !== null) {
            return $segment->with([
                'generated_video' => $segment->backgroundVideo,
                'status' => 'reused',
            ]);
        }

        $opts = [
            'duration' => $segment->duration,
            'resolution' => $options['resolution'] ?? $this->config['resolution'],
        ];

        if ($segment->style !== null) {
            $opts['style'] = $segment->style;
        }

        if ($segment->model !== null) {
            $opts = array_merge($opts, $segment->model->toOptions());
        } elseif ($this->config['default_model'] instanceof ModelBinding) {
            $opts = array_merge($opts, $this->config['default_model']->toOptions());
        }

        try {
            $video = $segment->backgroundImage !== null
                ? $this->gateway->imageToVideo(
                    $segment->backgroundImage,
                    $segment->prompt !== '' ? $segment->prompt : null,
                    $opts
                )
                : $this->gateway->textToVideo($segment->prompt, $opts);

            return $segment->with([
                'generated_video' => $video->firstVideo(),
                'status' => 'generated',
            ]);
        } catch (\Throwable $e) {
            $this->log('error', "片段 {$segment->id} 生成失败", [
                'error' => $e->getMessage(),
            ]);
            return $segment->with(['status' => 'failed']);
        }
    }

    private function successCount(): int
    {
        $n = 0;
        foreach ($this->segments as $s) {
            if ($s->hasGeneratedVideo()) {
                $n++;
            }
        }
        return $n;
    }

    private function failedCount(): int
    {
        return count($this->segments) - $this->successCount();
    }

    private function generateId(): string
    {
        return sprintf('drama-%s-%s', date('Ymd-His'), bin2hex(random_bytes(4)));
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $message = "[DramaDirector] {$message}";
        if ($this->logger !== null) {
            $this->logger->$level($message, $context);
        }
        LogManager::channel('drama')->$level($message, $context);
    }
}
