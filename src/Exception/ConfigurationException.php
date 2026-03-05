<?php

declare(strict_types=1);

namespace Kode\AiAgent\Exception;

/**
 * 配置异常
 * 
 * 当配置错误时抛出此异常。
 * 
 * @package Kode\AiAgent\Exception
 * 
 * 错误码范围: 1-999 (客户端错误)
 */
class ConfigurationException extends \RuntimeException implements AiAgentException
{
    public function __construct(
        string $message,
        private int $errorCode = 100,
        private array $context = [],
        ?\Throwable $previous = null
    ) {
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

    /**
     * 创建缺少配置异常
     */
    public static function missing(string $key): self
    {
        return new self(
            "缺少配置: {$key}",
            101,
            ['key' => $key]
        );
    }

    /**
     * 创建无效配置异常
     */
    public static function invalid(string $key, string $reason): self
    {
        return new self(
            "无效配置 {$key}: {$reason}",
            102,
            ['key' => $key, 'reason' => $reason]
        );
    }

    /**
     * 创建不支持的平台异常
     */
    public static function unsupportedPlatform(string $platform): self
    {
        return new self(
            "不支持的平台: {$platform}",
            103,
            ['platform' => $platform]
        );
    }
}
