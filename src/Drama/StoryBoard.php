<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

final readonly class StoryBoard
{
    public function __construct(
        public string $id,
        public string $title,
        public string $script,
        public array $scenes,
        public string $style = 'cinematic',
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'script' => $this->script,
            'scenes' => array_map(fn($s) => $s->toArray(), $this->scenes),
            'style' => $this->style,
            'metadata' => $this->metadata,
        ];
    }
}
