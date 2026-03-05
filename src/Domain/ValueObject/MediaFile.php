<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\ValueObject;

use Kode\AiAgent\Exception\InvalidInputException;

/**
 * 媒体文件值对象
 * 
 * 表示上传的媒体文件（视频、音频）的元数据和验证规则。
 * 
 * @package Kode\AiAgent\Domain\ValueObject
 * 
 * @example
 * ```php
 * $file = new MediaFile(
 *     name: 'video.mp4',
 *     path: '/tmp/upload/video.mp4',
 *     size: 10240000,
 *     mimeType: 'video/mp4',
 *     type: 'video'
 * );
 * ```
 */
readonly class MediaFile
{
    public const TYPE_VIDEO = 'video';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_IMAGE = 'image';

    private const ALLOWED_VIDEO_TYPES = [
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video/x-msvideo',
    ];

    private const ALLOWED_AUDIO_TYPES = [
        'audio/mpeg',
        'audio/wav',
        'audio/ogg',
        'audio/webm',
        'audio/aac',
    ];

    private const ALLOWED_IMAGE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private const MAX_VIDEO_SIZE = 500 * 1024 * 1024; // 500MB
    private const MAX_AUDIO_SIZE = 50 * 1024 * 1024;  // 50MB
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024;  // 10MB

    public function __construct(
        public string $name,
        public string $path,
        public int $size,
        public string $mimeType,
        public string $type,
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if (empty($this->name)) {
            throw new InvalidInputException('文件名不能为空');
        }

        if (empty($this->path)) {
            throw new InvalidInputException('文件路径不能为空');
        }

        if ($this->size <= 0) {
            throw new InvalidInputException('文件大小必须大于0');
        }

        $this->validateMimeType();
        $this->validateFileSize();
    }

    private function validateMimeType(): void
    {
        $allowedTypes = match ($this->type) {
            self::TYPE_VIDEO => self::ALLOWED_VIDEO_TYPES,
            self::TYPE_AUDIO => self::ALLOWED_AUDIO_TYPES,
            self::TYPE_IMAGE => self::ALLOWED_IMAGE_TYPES,
            default => throw new InvalidInputException("不支持的文件类型: {$this->type}"),
        };

        if (!in_array($this->mimeType, $allowedTypes, true)) {
            throw new InvalidInputException(
                "不支持的 MIME 类型: {$this->mimeType}，允许的类型: " . implode(', ', $allowedTypes)
            );
        }
    }

    private function validateFileSize(): void
    {
        $maxSize = match ($this->type) {
            self::TYPE_VIDEO => self::MAX_VIDEO_SIZE,
            self::TYPE_AUDIO => self::MAX_AUDIO_SIZE,
            self::TYPE_IMAGE => self::MAX_IMAGE_SIZE,
            default => throw new InvalidInputException("不支持的文件类型: {$this->type}"),
        };

        if ($this->size > $maxSize) {
            $maxSizeMb = round($maxSize / 1024 / 1024, 2);
            $actualSizeMb = round($this->size / 1024 / 1024, 2);
            throw new InvalidInputException(
                "文件大小超过限制 (最大: {$maxSizeMb}MB, 实际: {$actualSizeMb}MB)"
            );
        }
    }

    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    public function isAudio(): bool
    {
        return $this->type === self::TYPE_AUDIO;
    }

    public function isImage(): bool
    {
        return $this->type === self::TYPE_IMAGE;
    }

    public function getExtension(): string
    {
        return pathinfo($this->name, PATHINFO_EXTENSION);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'size' => $this->size,
            'mime_type' => $this->mimeType,
            'type' => $this->type,
            'extension' => $this->getExtension(),
        ];
    }

    public function __toString(): string
    {
        return $this->path;
    }
}
