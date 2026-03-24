<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

/**
 * 场景
 */
final class Scene
{
    public function __construct(
        public string $id,
        public int $order,
        public string $description,
        public string $style = 'cinematic',
        public int $duration = 10,
        public array $metadata = [],
        public ?string $imageUrl = null,
        public ?string $videoUrl = null,
    ) {}

    public function withImage(string $url): self
    {
        return new self(
            $this->id,
            $this->order,
            $this->description,
            $this->style,
            $this->duration,
            $this->metadata,
            $url,
            $this->videoUrl,
        );
    }

    public function withVideo(string $url): self
    {
        return new self(
            $this->id,
            $this->order,
            $this->description,
            $this->style,
            $this->duration,
            $this->metadata,
            $this->imageUrl,
            $url,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order' => $this->order,
            'description' => $this->description,
            'style' => $this->style,
            'duration' => $this->duration,
            'image_url' => $this->imageUrl,
            'video_url' => $this->videoUrl,
            'metadata' => $this->metadata,
        ];
    }
}
