<?php

declare(strict_types=1);

namespace Kode\AiAgent\Exception;

/**
 * 认证异常
 * 
 * 当认证失败时抛出此异常。
 * 
 * @package Kode\AiAgent\Exception
 * 
 * 错误码范围: 2000-2999
 */
class AuthenticationException extends \RuntimeException implements AiAgentException
{
    public function __construct(
        string $message,
        private int $errorCode = 2001,
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
     * 创建 API Key 无效异常
     */
    public static function invalidApiKey(string $provider): self
    {
        return new self(
            "API Key 无效或已过期",
            2001,
            ['provider' => $provider]
        );
    }

    /**
     * 创建 API Key 缺失异常
     */
    public static function missingApiKey(string $provider): self
    {
        return new self(
            "API Key 未配置",
            2002,
            ['provider' => $provider]
        );
    }

    /**
     * 创建权限不足异常
     */
    public static function insufficientPermissions(string $resource): self
    {
        return new self(
            "权限不足: {$resource}",
            2003,
            ['resource' => $resource]
        );
    }
}
