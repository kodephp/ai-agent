<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

readonly class SceneVideo
{
    public function __construct(
        public string $sceneId,
        public int $order,
        public string $videoUrl,
        public float $duration,
        public ?string $imageUrl = null,
    ) {}
}
