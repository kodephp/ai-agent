<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

/**
 * 故事板 V2
 *
 * 增强版故事板，支持转场管理、参考图/视频等。
 *
 * @package Kode\AiAgent\Drama
 */
readonly class StoryBoardV2
{
    public function __construct(
        public string $id,
        public string $title,
        public string $script,
        public array $scenes,
        public string $style = 'cinematic',
        public ?TransitionManager $transitionManager = null,
        public ?string $referenceImage = null,
        public ?string $referenceVideo = null,
        public array $metadata = [],
    ) {}

    /**
     * 添加参考图
     */
    public function withReferenceImage(string $url): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            script: $this->script,
            scenes: $this->scenes,
            style: $this->style,
            transitionManager: $this->transitionManager,
            referenceImage: $url,
            referenceVideo: $this->referenceVideo,
            metadata: $this->metadata,
        );
    }

    /**
     * 添加参考视频
     */
    public function withReferenceVideo(string $url): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            script: $this->script,
            scenes: $this->scenes,
            style: $this->style,
            transitionManager: $this->transitionManager,
            referenceImage: $this->referenceImage,
            referenceVideo: $url,
            metadata: $this->metadata,
        );
    }

    /**
     * 添加场景
     */
    public function withScenes(array $scenes): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            script: $this->script,
            scenes: $scenes,
            style: $this->style,
            transitionManager: $this->transitionManager,
            referenceImage: $this->referenceImage,
            referenceVideo: $this->referenceVideo,
            metadata: $this->metadata,
        );
    }

    /**
     * 获取场景数量
     */
    public function scenesCount(): int
    {
        return count($this->scenes);
    }

    /**
     * 获取总时长
     */
    public function totalDuration(): int
    {
        $total = 0;
        foreach ($this->scenes as $scene) {
            $total += $scene->duration;
        }
        return $total;
    }

    /**
     * 是否有参考图
     */
    public function hasReferenceImage(): bool
    {
        return $this->referenceImage !== null;
    }

    /**
     * 是否有参考视频
     */
    public function hasReferenceVideo(): bool
    {
        return $this->referenceVideo !== null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'script' => $this->script,
            'scenes' => array_map(fn($s) => $s->toArray(), $this->scenes),
            'style' => $this->style,
            'transition_count' => $this->transitionManager?->count() ?? 0,
            'reference_image' => $this->referenceImage,
            'reference_video' => $this->referenceVideo,
            'metadata' => $this->metadata,
        ];
    }
}