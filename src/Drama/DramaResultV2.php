<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

/**
 * 短剧结果 V2
 *
 * 包含更丰富的生成结果信息。
 *
 * @package Kode\AiAgent\Drama
 */
final readonly class DramaResultV2
{
    public function __construct(
        public string $id,
        public string $video,
        public StoryBoardV2 $storyBoard,
        public array $scenes,
        public array $sceneVideos,
        public float $duration,
        public array $metadata = [],
    ) {}

    /**
     * 获取场景数量
     */
    public function scenesCount(): int
    {
        return count($this->scenes);
    }

    /**
     * 获取视频数量
     */
    public function videosCount(): int
    {
        return count($this->sceneVideos);
    }

    /**
     * 获取总视频时长
     */
    public function totalDuration(): float
    {
        return $this->metadata['total_duration'] ?? $this->duration;
    }

    /**
     * 获取转场数量
     */
    public function transitionsCount(): int
    {
        return $this->metadata['transitions_count'] ?? 0;
    }

    /**
     * 检查是否有开场
     */
    public function hasOpening(): bool
    {
        return $this->metadata['has_opening'] ?? false;
    }

    /**
     * 检查是否有结尾
     */
    public function hasClosing(): bool
    {
        return $this->metadata['has_closing'] ?? false;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'video' => $this->video,
            'storyboard' => $this->storyBoard->toArray(),
            'scenes' => array_map(fn($s) => $s->toArray(), $this->scenes),
            'scene_videos' => count($this->sceneVideos),
            'duration' => $this->duration,
            'metadata' => $this->metadata,
        ];
    }
}