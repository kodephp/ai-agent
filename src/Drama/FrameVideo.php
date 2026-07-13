<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

/**
 * 帧类型
 */
enum FrameType: string
{
    case OPENING = 'opening';
    case CLOSING = 'closing';
}

/**
 * 字幕配置
 */
final class SubtitleConfig
{
    public function __construct(
        public string $text,
        public string $position = 'bottom',
        public string $font = 'Arial',
        public int $fontSize = 24,
        public string $color = 'white',
        public string $backgroundColor = 'black@0.5',
    ) {}

    /**
     * 获取 FFmpeg 字幕滤镜
     */
    public function toFFmpegFilter(): string
    {
        $escapedText = addslashes($this->text);
        return sprintf(
            "drawtext=text='%s':fontsize=%d:fontcolor=%s:font=%s:shadowcolor=black:shadowx=2:shadowy=2:x=(w-text_w)/2:y=h-%d",
            $escapedText,
            $this->fontSize,
            $this->color,
            $this->font,
            $this->fontSize * 2
        );
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'position' => $this->position,
            'font' => $this->font,
            'font_size' => $this->fontSize,
            'color' => $this->color,
            'background_color' => $this->backgroundColor,
        ];
    }
}

/**
 * 帧视频配置
 *
 * 表示开场/结尾视频的配置，支持自定义时长、字幕等。
 *
 * @package Kode\AiAgent\Drama
 *
 * @example
 * ```php
 * // 创建开场视频
 * $opening = FrameVideo::opening('https://cdn.example.com/intro.mp4', [
 *     'duration' => 5,
 *     'title' => '精彩故事即将开始',
 * ]);
 *
 * // 创建结尾视频
 * $closing = FrameVideo::closing('https://cdn.example.com/outro.mp4', [
 *     'duration' => 10,
 *     'subtitle' => new SubtitleConfig('感谢观看'),
 * ]);
 * ```
 */
final class FrameVideo
{
    private function __construct(
        public FrameType $type,
        public string $url,
        public int $duration,
        public array $metadata = [],
        public ?SubtitleConfig $subtitle = null,
        public ?string $generatedVideoUrl = null,
    ) {}

    /**
     * 创建开场视频
     */
    public static function opening(
        string $url,
        array $options = [],
    ): self {
        return new self(
            type: FrameType::OPENING,
            url: $url,
            duration: $options['duration'] ?? 5,
            metadata: $options['metadata'] ?? [],
            subtitle: isset($options['subtitle']) && $options['subtitle'] instanceof SubtitleConfig
                ? $options['subtitle']
                : (isset($options['title'])
                    ? new SubtitleConfig($options['title'])
                    : null),
        );
    }

    /**
     * 创建结尾视频
     */
    public static function closing(
        string $url,
        array $options = [],
    ): self {
        return new self(
            type: FrameType::CLOSING,
            url: $url,
            duration: $options['duration'] ?? 10,
            metadata: $options['metadata'] ?? [],
            subtitle: isset($options['subtitle']) && $options['subtitle'] instanceof SubtitleConfig
                ? $options['subtitle']
                : (isset($options['ending_text'])
                    ? new SubtitleConfig($options['ending_text'])
                    : null),
        );
    }

    /**
     * 是否是开场
     */
    public function isOpening(): bool
    {
        return $this->type === FrameType::OPENING;
    }

    /**
     * 是否是结尾
     */
    public function isClosing(): bool
    {
        return $this->type === FrameType::CLOSING;
    }

    /**
     * 是否有字幕
     */
    public function hasSubtitle(): bool
    {
        return $this->subtitle !== null;
    }

    /**
     * 设置生成的视频 URL
     */
    public function withGeneratedVideo(string $url): self
    {
        return new self(
            type: $this->type,
            url: $this->url,
            duration: $this->duration,
            metadata: $this->metadata,
            subtitle: $this->subtitle,
            generatedVideoUrl: $url,
        );
    }

    /**
     * 获取视频 URL（优先使用生成的）
     */
    public function getVideoUrl(): string
    {
        return $this->generatedVideoUrl ?? $this->url;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'url' => $this->url,
            'duration' => $this->duration,
            'subtitle' => $this->subtitle?->toArray(),
            'metadata' => $this->metadata,
        ];
    }
}

/**
 * 帧视频管理器
 *
 * 管理开场和结尾视频，支持自动生成和定制。
 *
 * @package Kode\AiAgent\Drama
 *
 * @example
 * ```php
 * $manager = new FrameVideoManager();
 *
 * // 设置开场视频
 * $manager->setOpening(FrameVideo::opening('https://example.com/intro.mp4', [
 *     'duration' => 5,
 *     'title' => '精彩故事即将开始',
 * ]));
 *
 * // 设置结尾视频
 * $manager->setClosing(FrameVideo::closing('https://example.com/outro.mp4', [
 *     'duration' => 10,
 *     'ending_text' => '感谢观看',
 * ]));
 *
 * // 获取总时长
 * echo $manager->getTotalDuration();
 * ```
 */
final class FrameVideoManager
{
    private ?FrameVideo $opening = null;
    private ?FrameVideo $closing = null;

    /**
     * 设置开场视频
     */
    public function setOpening(FrameVideo $opening): self
    {
        if (!$opening->isOpening()) {
            throw new \InvalidArgumentException('必须是开场视频');
        }
        $this->opening = $opening;
        return $this;
    }

    /**
     * 设置结尾视频
     */
    public function setClosing(FrameVideo $closing): self
    {
        if (!$closing->isClosing()) {
            throw new \InvalidArgumentException('必须是结尾视频');
        }
        $this->closing = $closing;
        return $this;
    }

    /**
     * 获取开场视频
     */
    public function getOpening(): ?FrameVideo
    {
        return $this->opening;
    }

    /**
     * 获取结尾视频
     */
    public function getClosing(): ?FrameVideo
    {
        return $this->closing;
    }

    /**
     * 检查是否有开场
     */
    public function hasOpening(): bool
    {
        return $this->opening !== null;
    }

    /**
     * 检查是否有结尾
     */
    public function hasClosing(): bool
    {
        return $this->closing !== null;
    }

    /**
     * 获取开场时长
     */
    public function getOpeningDuration(): int
    {
        return $this->opening->duration;
    }

    /**
     * 获取结尾时长
     */
    public function getClosingDuration(): int
    {
        return $this->closing->duration;
    }

    /**
     * 获取前尾帧总时长
     */
    public function getTotalDuration(): int
    {
        return $this->getOpeningDuration() + $this->getClosingDuration();
    }

    /**
     * 获取所有帧视频（按顺序）
     *
     * @return FrameVideo[]
     */
    public function getAll(): array
    {
        $frames = [];
        if ($this->opening !== null) {
            $frames[] = $this->opening;
        }
        if ($this->closing !== null) {
            $frames[] = $this->closing;
        }
        return $frames;
    }

    /**
     * 清除所有帧视频
     */
    public function clear(): self
    {
        $this->opening = null;
        $this->closing = null;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'opening' => $this->opening?->toArray(),
            'closing' => $this->closing?->toArray(),
            'total_duration' => $this->getTotalDuration(),
        ];
    }
}