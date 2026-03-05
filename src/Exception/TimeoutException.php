<?php

declare(strict_types=1);

namespace Kode\AiAgent\Exception;

/**
 * 超时异常
 * 
 * 当请求超时时抛出此异常。
 * 
 * @package Kode\AiAgent\Exception
 * 
 * 错误码范围: 1000-1999 (平台错误)
 */
class TimeoutException extends \RuntimeException implements AiAgentException
{
    public function __construct(
        string $message,
        private int $errorCode = 1006,
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
     * 创建连接超时异常
     */
    public static function connection(int $timeout, string $url): self
    {
        return new self(
            "连接超时: {$timeout}秒",
            1006,
            ['timeout' => $timeout, 'url' => $url]
        );
    }

    /**
     * 创建请求超时异常
     */
    public static function request(int $timeout, string $operation): self
    {
        return new self(
            "请求超时: {$operation} 超过 {$timeout}秒",
            1006,
            ['timeout' => $timeout, 'operation' => $operation]
        );
    }

    /**
     * 创建流式响应超时异常
     */
    public static function stream(int $timeout): self
    {
        return new self(
            "流式响应超时: {$timeout}秒无数据",
            1006,
            ['timeout' => $timeout, 'type' => 'stream']
        );
    }
}
