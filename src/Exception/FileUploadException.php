<?php

declare(strict_types=1);

namespace Kode\AiAgent\Exception;

/**
 * 文件上传异常
 * 
 * 处理文件上传过程中的各种错误情况。
 * 
 * @package Kode\AiAgent\Exception
 * 
 * 错误码范围: 3000-3999
 */
class FileUploadException extends \RuntimeException implements AiAgentException
{
    public const CODE_FILE_TOO_LARGE = 3001;
    public const CODE_INVALID_TYPE = 3002;
    public const CODE_UPLOAD_FAILED = 3003;
    public const CODE_PERMISSION_DENIED = 3004;
    public const CODE_DISK_FULL = 3005;
    public const CODE_FILE_CORRUPT = 3006;
    public const CODE_TEMP_FILE_MISSING = 3007;

    private const ERROR_MESSAGES = [
        self::CODE_FILE_TOO_LARGE => '文件大小超过限制',
        self::CODE_INVALID_TYPE => '不支持的文件类型',
        self::CODE_UPLOAD_FAILED => '文件上传失败',
        self::CODE_PERMISSION_DENIED => '没有权限访问文件',
        self::CODE_DISK_FULL => '磁盘空间不足',
        self::CODE_FILE_CORRUPT => '文件损坏',
        self::CODE_TEMP_FILE_MISSING => '临时文件丢失',
    ];

    public function __construct(
        string $message = '',
        protected int $errorCode = self::CODE_UPLOAD_FAILED,
        protected array $context = [],
        ?\Throwable $previous = null
    ) {
        if ($message === '') {
            $message = self::ERROR_MESSAGES[$errorCode] ?? '文件上传错误';
        }
        parent::__construct($message, $errorCode, $previous);
    }

    public function errorCode(): int
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->context;
    }

    public static function fileTooLarge(int $maxSize, int $actualSize, array $context = []): self
    {
        return new self(
            sprintf('文件大小超过限制 (最大: %d bytes, 实际: %d bytes)', $maxSize, $actualSize),
            self::CODE_FILE_TOO_LARGE,
            array_merge(['max_size' => $maxSize, 'actual_size' => $actualSize], $context)
        );
    }

    public static function invalidType(string $mimeType, array $allowedTypes, array $context = []): self
    {
        return new self(
            sprintf('不支持的文件类型: %s，允许的类型: %s', $mimeType, implode(', ', $allowedTypes)),
            self::CODE_INVALID_TYPE,
            array_merge(['mime_type' => $mimeType, 'allowed_types' => $allowedTypes], $context)
        );
    }

    public static function uploadFailed(string $reason, array $context = []): self
    {
        return new self(
            sprintf('文件上传失败: %s', $reason),
            self::CODE_UPLOAD_FAILED,
            array_merge(['reason' => $reason], $context)
        );
    }

    public static function permissionDenied(string $path, array $context = []): self
    {
        return new self(
            sprintf('没有权限访问文件: %s', $path),
            self::CODE_PERMISSION_DENIED,
            array_merge(['path' => $path], $context)
        );
    }

    public static function diskFull(array $context = []): self
    {
        return new self(
            '磁盘空间不足',
            self::CODE_DISK_FULL,
            $context
        );
    }
}
