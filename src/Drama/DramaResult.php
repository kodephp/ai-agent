<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

final readonly class DramaResult
{
    public function __construct(
        public string $id,
        public string $video,
        public array $scenes,
        public float $duration,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'video' => $this->video,
            'scenes' => array_map(fn($s) => $s->toArray(), $this->scenes),
            'duration' => $this->duration,
        ];
    }
}
