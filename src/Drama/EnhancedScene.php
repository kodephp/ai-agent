<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

/**
 * 场景类型枚举
 */
enum SceneType: string
{
    case MAIN = 'main';
    case OPENING = 'opening';
    case CLOSING = 'closing';
    case TRANSITION = 'transition';
}

/**
 * 场景类
 *
 * 短剧中的单个场景，支持参考图、参考视频、转场效果等高级配置。
 *
 * @package Kode\AiAgent\Drama
 *
 * @example
 * ```php
 * $scene = new EnhancedScene(
 *     id: 'scene-1',
 *     order: 1,
 *     description: '阳光明媚的公园里，主人公漫步',
 *     type: SceneType::MAIN,
 *     duration: 10,
 *     referenceImage: 'https://example.com/ref.jpg',
 *     referenceVideo: 'https://example.com/ref.mp4',
 * );
 * ```
 */
final class EnhancedScene
{
    public function __construct(
        public string $id,
        public int $order,
        public string $description,
        public SceneType $type = SceneType::MAIN,
        public string $style = 'cinematic',
        public int $duration = 10,
        public array $metadata = [],
        public ?string $imageUrl = null,
        public ?string $videoUrl = null,
        public ?string $referenceImage = null,
        public ?string $referenceVideo = null,
        public ?string $transitionEffect = null,
        public ?int $transitionDuration = null,
    ) {}

    /**
     * 是否是主场景
     */
    public function isMain(): bool
    {
        return $this->type === SceneType::MAIN;
    }

    /**
     * 是否是开场
     */
    public function isOpening(): bool
    {
        return $this->type === SceneType::OPENING;
    }

    /**
     * 是否是结尾
     */
    public function isClosing(): bool
    {
        return $this->type === SceneType::CLOSING;
    }

    /**
     * 是否是转场
     */
    public function isTransition(): bool
    {
        return $this->type === SceneType::TRANSITION;
    }

    /**
     * 是否使用参考图
     */
    public function hasReferenceImage(): bool
    {
        return $this->referenceImage !== null;
    }

    /**
     * 是否使用参考视频
     */
    public function hasReferenceVideo(): bool
    {
        return $this->referenceVideo !== null;
    }

    /**
     * 是否使用转场效果
     */
    public function hasTransition(): bool
    {
        return $this->transitionEffect !== null;
    }

    /**
     * 获取有效时长
     *
     * 如果是转场场景，有效时长会减去转场持续时间
     */
    public function effectiveDuration(): int
    {
        if ($this->isTransition() && $this->transitionDuration !== null) {
            return max(1, $this->duration - $this->transitionDuration);
        }
        return $this->duration;
    }

    /**
     * 设置生成的图像
     */
    public function withImage(string $url): self
    {
        return new self(
            id: $this->id,
            order: $this->order,
            description: $this->description,
            type: $this->type,
            style: $this->style,
            duration: $this->duration,
            metadata: $this->metadata,
            imageUrl: $url,
            videoUrl: $this->videoUrl,
            referenceImage: $this->referenceImage,
            referenceVideo: $this->referenceVideo,
            transitionEffect: $this->transitionEffect,
            transitionDuration: $this->transitionDuration,
        );
    }

    /**
     * 设置生成的视频
     */
    public function withVideo(string $url): self
    {
        return new self(
            id: $this->id,
            order: $this->order,
            description: $this->description,
            type: $this->type,
            style: $this->style,
            duration: $this->duration,
            metadata: $this->metadata,
            imageUrl: $this->imageUrl,
            videoUrl: $url,
            referenceImage: $this->referenceImage,
            referenceVideo: $this->referenceVideo,
            transitionEffect: $this->transitionEffect,
            transitionDuration: $this->transitionDuration,
        );
    }

    /**
     * 添加转场效果
     */
    public function withTransition(string $effect, int $duration = 1): self
    {
        return new self(
            id: $this->id,
            order: $this->order,
            description: $this->description,
            type: $this->type,
            style: $this->style,
            duration: $this->duration,
            metadata: $this->metadata,
            imageUrl: $this->imageUrl,
            videoUrl: $this->videoUrl,
            referenceImage: $this->referenceImage,
            referenceVideo: $this->referenceVideo,
            transitionEffect: $effect,
            transitionDuration: $duration,
        );
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order' => $this->order,
            'description' => $this->description,
            'type' => $this->type->value,
            'style' => $this->style,
            'duration' => $this->duration,
            'effective_duration' => $this->effectiveDuration(),
            'image_url' => $this->imageUrl,
            'video_url' => $this->videoUrl,
            'reference_image' => $this->referenceImage,
            'reference_video' => $this->referenceVideo,
            'transition_effect' => $this->transitionEffect,
            'transition_duration' => $this->transitionDuration,
            'metadata' => $this->metadata,
        ];
    }
}