<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama\Director;

use Kode\AiAgent\Drama\TransitionType;

/**
 * 漫剧分镜（片段）
 *
 * 表示一个镜头/片段：提示词、转场、背景图、背景视频、模型绑定等。
 * 通过 with() 生成新实例以支持单段调整/重生成。
 *
 * @package Kode\AiAgent\Drama\Director
 */
final class DramaSegment
{
    /**
     * @param string $id 片段唯一 ID（如 seg-1）
     * @param int $order 序号（从 1 开始）
     * @param string $title 标题
     * @param string $prompt 视频生成提示词
     * @param TransitionType $transition 到下一个片段的转场
     * @param string|null $backgroundImage 背景图（URL / 本地路径）
     * @param string|null $backgroundVideo 背景视频（直接复用为片段视频）
     * @param ModelBinding|null $model 绑定的视频生成模型
     * @param int $duration 时长（秒）
     * @param string|null $style 风格
     * @param string|null $generatedVideo 生成后的视频 URL/路径
     * @param string $status 视频状态：pending | generated | reused | failed
     * @param string $audioText 旁白 / 口播文本（用于 TTS 合成）
     * @param ModelBinding|null $tts 绑定的 TTS（音频）模型（含 voice / instructions）
     * @param string|null $audioUrl 合成后的音频 URL/路径
     * @param string $audioStatus 音频状态：pending | generated | failed
     * @param string|null $subtitle 字幕文本（覆盖自动旁白）
     */
    public function __construct(
        public string $id,
        public int $order,
        public string $title,
        public string $prompt,
        public TransitionType $transition = TransitionType::FADE,
        public ?string $backgroundImage = null,
        public ?string $backgroundVideo = null,
        public ?ModelBinding $model = null,
        public int $duration = 5,
        public ?string $style = null,
        public ?string $generatedVideo = null,
        public string $status = 'pending',
        public string $audioText = '',
        public ?ModelBinding $tts = null,
        public ?string $audioUrl = null,
        public string $audioStatus = 'pending',
        public ?string $subtitle = null,
    ) {}

    /**
     * 生成带覆盖字段的新实例（用于单段调整/重生成 / CRUD）
     *
     * @param array<string, mixed> $values
     */
    public function with(array $values): self
    {
        return new self(
            id: $values['id'] ?? $this->id,
            order: $values['order'] ?? $this->order,
            title: $values['title'] ?? $this->title,
            prompt: $values['prompt'] ?? $this->prompt,
            transition: $values['transition'] ?? $this->transition,
            backgroundImage: $values['background_image'] ?? $values['backgroundImage'] ?? $this->backgroundImage,
            backgroundVideo: $values['background_video'] ?? $values['backgroundVideo'] ?? $this->backgroundVideo,
            model: $values['model'] ?? $this->model,
            duration: $values['duration'] ?? $this->duration,
            style: $values['style'] ?? $this->style,
            generatedVideo: $values['generated_video'] ?? $values['generatedVideo'] ?? $this->generatedVideo,
            status: $values['status'] ?? $this->status,
            audioText: $values['audio_text'] ?? $values['audioText'] ?? $this->audioText,
            tts: $values['tts'] ?? $this->tts,
            audioUrl: $values['audio_url'] ?? $values['audioUrl'] ?? $this->audioUrl,
            audioStatus: $values['audio_status'] ?? $this->audioStatus,
            subtitle: $values['subtitle'] ?? $this->subtitle,
        );
    }

    public function hasGeneratedVideo(): bool
    {
        return $this->generatedVideo !== null && $this->generatedVideo !== '';
    }

    public function hasGeneratedAudio(): bool
    {
        return $this->audioUrl !== null && $this->audioUrl !== '';
    }

    /**
     * 是否有可用于合成音频的旁白文本
     */
    public function hasNarration(): bool
    {
        return trim($this->audioText) !== '';
    }

    /**
     * 实际用于字幕的文本（优先 subtitle，其次旁白）
     */
    public function subtitleText(): string
    {
        return trim($this->subtitle ?? $this->audioText);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order' => $this->order,
            'title' => $this->title,
            'prompt' => $this->prompt,
            'transition' => $this->transition->value,
            'background_image' => $this->backgroundImage,
            'background_video' => $this->backgroundVideo,
            'model' => $this->model?->toArray(),
            'duration' => $this->duration,
            'style' => $this->style,
            'generated_video' => $this->generatedVideo,
            'status' => $this->status,
            'audio_text' => $this->audioText,
            'tts' => $this->tts?->toArray(),
            'audio_url' => $this->audioUrl,
            'audio_status' => $this->audioStatus,
            'subtitle' => $this->subtitle,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? ('seg-' . ($data['order'] ?? 1)),
            order: (int) ($data['order'] ?? 1),
            title: $data['title'] ?? $data['prompt'] ?? '片段',
            prompt: $data['prompt'] ?? '',
            transition: isset($data['transition'])
                ? (TransitionType::tryFrom($data['transition']) ?? TransitionType::FADE)
                : TransitionType::FADE,
            backgroundImage: $data['background_image'] ?? $data['backgroundImage'] ?? null,
            backgroundVideo: $data['background_video'] ?? $data['backgroundVideo'] ?? null,
            model: isset($data['model'])
                ? (match (true) {
                    $data['model'] instanceof ModelBinding => $data['model'],
                    is_array($data['model']) => ModelBinding::fromArray($data['model']),
                    default => new ModelBinding(null, (string) $data['model']),
                })
                : null,
            duration: (int) ($data['duration'] ?? 5),
            style: $data['style'] ?? null,
            generatedVideo: $data['generated_video'] ?? $data['generatedVideo'] ?? null,
            status: $data['status'] ?? 'pending',
            audioText: $data['audio_text'] ?? $data['audioText'] ?? '',
            tts: isset($data['tts'])
                ? (match (true) {
                    $data['tts'] instanceof ModelBinding => $data['tts'],
                    is_array($data['tts']) => ModelBinding::fromArray($data['tts']),
                    default => new ModelBinding(null, (string) $data['tts']),
                })
                : null,
            audioUrl: $data['audio_url'] ?? $data['audioUrl'] ?? null,
            audioStatus: $data['audio_status'] ?? 'pending',
            subtitle: $data['subtitle'] ?? null,
        );
    }
}
