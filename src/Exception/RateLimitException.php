<?php

declare(strict_types=1);

namespace Kode\AiAgent\Exception;

/**
 * 频率限制异常
 * 
 * 当触发频率限制时抛出此异常。
 * 
 * @package Kode\AiAgent\Exception
 * 
 * 错误码范围: 3000-3999
 */
class RateLimitException extends \RuntimeException implements AiAgentException
{
    public function __construct(
        string $message,
        private int $errorCode = 3001,
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
     * 创建请求频率限制异常
     */
    public static function requestsPerMinute(int $limit, int $retryAfter = 0): self
    {
        return new self(
            "请求频率超限: 每分钟最多 {$limit} 次",
            3001,
            ['limit' => $limit, 'retry_after' => $retryAfter]
        );
    }

    /**
     * 创建 Token 频率限制异常
     */
    public static function tokensPerMinute(int $limit, int $retryAfter = 0): self
    {
        return new self(
            "Token 频率超限: 每分钟最多 {$limit} tokens",
            3002,
            ['limit' => $limit, 'retry_after' => $retryAfter]
        );
    }

    /**
     * 创建配额超限异常
     */
    public static function quotaExceeded(string $quotaType): self
    {
        return new self(
            "配额超限: {$quotaType}",
            3003,
            ['quota_type' => $quotaType]
        );
    }
}
