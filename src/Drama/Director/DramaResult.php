<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama\Director;

/**
 * 漫剧生成结果
 *
 * @package Kode\AiAgent\Drama\Director
 */
final class DramaResult
{
    /**
     * @param string $id 结果 ID
     * @param string $finalVideo 合成后的最终视频 URL/路径
     * @param array<int, DramaSegment> $segments 所有分镜（含生成结果）
     * @param array<int, mixed> $sceneVideos 用于合成的场景视频列表
     * @param float $duration 总耗时（秒）
     * @param array<string, mixed> $metadata total_duration / transitions_count 等
     */
    public function __construct(
        public string $id,
        public ?string $finalVideo,
        public array $segments,
        public array $sceneVideos,
        public float $duration,
        public array $metadata = [],
    ) {}

    public function segmentCount(): int
    {
        return count($this->segments);
    }

    public function successCount(): int
    {
        $n = 0;
        foreach ($this->segments as $s) {
            if ($s->hasGeneratedVideo()) {
                $n++;
            }
        }
        return $n;
    }

    public function failedCount(): int
    {
        return $this->segmentCount() - $this->successCount();
    }

    /**
     * 汇总统计
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return [
            'id' => $this->id,
            'final_video' => $this->finalVideo,
            'total' => $this->segmentCount(),
            'success' => $this->successCount(),
            'failed' => $this->failedCount(),
            'duration' => $this->duration,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'final_video' => $this->finalVideo,
            'segment_count' => $this->segmentCount(),
            'success_count' => $this->successCount(),
            'failed_count' => $this->failedCount(),
            'duration' => $this->duration,
            'metadata' => $this->metadata,
            'segments' => array_map(static fn(DramaSegment $s) => $s->toArray(), $this->segments),
        ];
    }
}
