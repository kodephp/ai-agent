<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama\Director;

use Kode\AiAgent\AudioGateway\AudioGateway;
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
     * @param AudioGateway|null $audioGateway 统一音频（TTS）网关；传入后 generate/regenerate
     *        会为含旁白的片段合成配音，compose 会把每段音频与字幕合成进最终视频。
     */
    public function __construct(
        private VideoGateway $gateway,
        array $config = [],
    private ?LoggerInterface $logger = null,
    /** @var callable|null */
    private $composerFactory = null,
    private ?AudioGateway $audioGateway = null,
    ) {
        $this->config = array_merge([
            'resolution' => '1080p',
            'transition_duration' => 1,
            'concurrency' => 4,
            'output_dir' => 'var/drama/output',
            'audio_output_dir' => 'var/drama/audio',
            'background_music' => null,
            'default_model' => null,
            'default_transition' => 'fade',
            'default_duration' => 5,
        ], $config);
    }

    /**
     * 设置统一音频（TTS）网关（链式）
     */
    public function setAudioGateway(AudioGateway $audioGateway): self
    {
        $this->audioGateway = $audioGateway;
        return $this;
    }

    public function hasAudioGateway(): bool
    {
        return $this->audioGateway !== null;
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
            $this->segments[$index] = $this->generateSegmentAudio($this->segments[$index]);
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
        $updated = $this->generateSegmentAudio($updated);
        $this->segments[$index] = $updated;

        return $updated;
    }

    /**
     * 单段配音（重）生成：为含旁白的片段合成 TTS 音频
     *
     * 不自动合成视频，调用方随后调用 compose() 重新合成最终视频。
     *
     * @param int $index 片段序号（从 0 开始）
     * @param array<string, mixed> $options 覆盖：audio_text / tts / voice
     */
    public function regenerateSegmentAudio(int $index, array $options = []): DramaSegment
    {
        if (!isset($this->segments[$index])) {
            throw new \OutOfRangeException("片段序号越界：{$index}");
        }

        $segment = $this->segments[$index];

        if (isset($options['audio_text'])) {
            $segment = $segment->with(['audio_text' => $options['audio_text']]);
        }
        if (isset($options['subtitle'])) {
            $segment = $segment->with(['subtitle' => $options['subtitle']]);
        }
        if (isset($options['tts'])) {
            $binding = $options['tts'] instanceof ModelBinding
                ? $options['tts']
                : ModelBinding::fromArray($options['tts']);
            $segment = $segment->with(['tts' => $binding]);
        }

        $updated = $this->generateSegmentAudio($segment);
        $this->segments[$index] = $updated;

        return $updated;
    }

    /**
     * 为所有片段（重）生成配音
     */
    public function generateAudioForAll(array $options = []): self
    {
        foreach ($this->segments as $index => $segment) {
            $this->segments[$index] = $this->generateSegmentAudio($segment, $options);
        }
        return $this;
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
                audioUrl: $segment->hasGeneratedAudio() ? $segment->audioUrl : null,
                subtitle: $segment->subtitleText() !== '' ? $segment->subtitleText() : null,
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
     * 按序号或 ID 查找分镜
     */
    public function findSegment(int|string $id): ?DramaSegment
    {
        if (is_int($id)) {
            return $this->segments[$id] ?? null;
        }
        foreach ($this->segments as $segment) {
            if ($segment->id === $id) {
                return $segment;
            }
        }
        return null;
    }

    /**
     * 获取单个分镜（不存在则抛异常）
     *
     * @param int|string $id 序号（从 0 开始）或 ID
     */
    public function getSegment(int|string $id): DramaSegment
    {
        $segment = $this->findSegment($id);
        if ($segment === null) {
            throw new \OutOfRangeException("片段不存在：{$id}");
        }
        return $segment;
    }

    /**
     * 追加分镜到末尾
     *
     * @param array<string, mixed>|string $data 结构化数组或纯提示词文本
     */
    public function addSegment(array|string $data): DramaSegment
    {
        $segment = $this->buildSegment($data, count($this->segments) + 1);
        $this->segments[] = $segment;
        return $segment;
    }

    /**
     * 在指定位置插入分镜（index 越界则追加到末尾）
     *
     * @param int $index 插入位置（从 0 开始）
     * @param array<string, mixed>|string $data 结构化数组或纯提示词文本
     */
    public function insertSegment(int $index, array|string $data): DramaSegment
    {
        $segment = $this->buildSegment($data, $index + 1);
        if ($index < 0 || $index >= count($this->segments)) {
            $this->segments[] = $segment;
        } else {
            array_splice($this->segments, $index, 0, [$segment]);
        }
        $this->renumber();
        return $segment;
    }

    /**
     * 更新分镜（覆盖字段）
     *
     * @param int|string $id 序号（从 0 开始）或 ID
     * @param array<string, mixed> $values 覆盖字段（prompt/transition/duration/model/tts/audio_text/subtitle/background_image/background_video/style 等）
     */
    public function updateSegment(int|string $id, array $values): DramaSegment
    {
        $index = $this->resolveIndex($id);
        $segment = $this->segments[$index];

        if (isset($values['model']) && !($values['model'] instanceof ModelBinding)) {
            $values['model'] = ModelBinding::fromArray($values['model']);
        }
        if (isset($values['tts']) && !($values['tts'] instanceof ModelBinding)) {
            $values['tts'] = ModelBinding::fromArray($values['tts']);
        }
        if (isset($values['transition']) && !($values['transition'] instanceof TransitionType)) {
            $values['transition'] = TransitionType::tryFrom($values['transition']) ?? TransitionType::FADE;
        }

        $updated = $segment->with($values);
        $this->segments[$index] = $updated;
        return $updated;
    }

    /**
     * 删除分镜
     *
     * @param int|string $id 序号（从 0 开始）或 ID
     */
    public function removeSegment(int|string $id): bool
    {
        $index = $this->resolveIndex($id);
        if ($index === null) {
            return false;
        }
        array_splice($this->segments, $index, 1);
        $this->renumber();
        return true;
    }

    /**
     * 替换全部分镜（用于从外部加载/重置剧本）
     *
     * @param array<int, array<string, mixed>|string> $items
     */
    public function setSegments(array $items): self
    {
        $splitter = new ScriptSplitter();
        $this->segments = $splitter->split($items, $this->config);
        return $this;
    }

    /**
     * 导出当前分镜为数组（便于持久化）
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn(DramaSegment $s) => $s->toArray(), $this->segments);
    }

    private function buildSegment(array|string $data, int $order): DramaSegment
    {
        if (is_string($data)) {
            $data = ['prompt' => $data];
        }
        if (!isset($data['id'])) {
            $data['id'] = $this->nextSegmentId();
        }
        $data['order'] = $order;
        return DramaSegment::fromArray($data);
    }

    /**
     * 生成全局唯一的片段 ID（避免插入/追加时与现有 ID 冲突）
     */
    private function nextSegmentId(): string
    {
        $max = 0;
        foreach ($this->segments as $segment) {
            if (preg_match('/^seg-(\d+)$/', $segment->id, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }
        return 'seg-' . ($max + 1);
    }

    private function resolveIndex(int|string $id): ?int
    {
        if (is_int($id)) {
            return isset($this->segments[$id]) ? $id : null;
        }
        foreach ($this->segments as $index => $segment) {
            if ($segment->id === $id) {
                return $index;
            }
        }
        return null;
    }

    private function renumber(): void
    {
        $order = 0;
        foreach ($this->segments as $index => $segment) {
            $order++;
            $this->segments[$index] = $segment->with(['order' => $order]);
        }
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

    /**
     * 生成单个分镜的配音（TTS）
     *
     * 无旁白文本或未配置音频网关时保持原样（audio_status = 'none'）。
     */
    private function generateSegmentAudio(DramaSegment $segment, array $options = []): DramaSegment
    {
        if ($this->audioGateway === null) {
            return $segment->with(['audio_status' => $segment->hasNarration() ? 'pending' : 'none']);
        }

        if (!$segment->hasNarration()) {
            return $segment->with(['audio_status' => 'none']);
        }

        $text = $options['audio_text'] ?? $segment->audioText;

        $ttsOptions = [];
        if ($segment->tts !== null) {
            $ttsOptions = $segment->tts->toOptions();
        }
        $ttsOptions = array_merge($ttsOptions, $options['tts_options'] ?? []);

        try {
            $audio = $this->audioGateway->textToSpeech($text, $ttsOptions);
        } catch (\Throwable $e) {
            $this->log('error', "片段 {$segment->id} 配音失败", [
                'error' => $e->getMessage(),
            ]);
            return $segment->with(['audio_status' => 'failed']);
        }

        if (!$audio->isSuccess()) {
            $this->log('error', "片段 {$segment->id} 配音失败", [
                'msg' => $audio->msg,
            ]);
            return $segment->with(['audio_status' => 'failed']);
        }

        return $segment->with([
            'audio_url' => $audio->firstAudio(),
            'audio_status' => 'generated',
        ]);
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
