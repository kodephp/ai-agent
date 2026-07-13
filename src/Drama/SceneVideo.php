<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

/**
 * 场景视频
 *
 * 表示短剧中的一个场景视频片段，包含视频URL、时长等信息。
 *
 * @package Kode\AiAgent\Drama
 */
readonly class SceneVideo
{
    public function __construct(
        public string $sceneId,
        public int $order,
        public string $videoUrl,
        public float $duration,
        public ?string $imageUrl = null,
        public ?string $audioUrl = null,
        public ?string $subtitle = null,
    ) {}
}
