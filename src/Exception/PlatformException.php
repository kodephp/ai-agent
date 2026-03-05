<?php

declare(strict_types=1);

namespace Kode\AiAgent\Exception;

/**
 * 平台异常
 * 
 * 当平台调用失败时抛出此异常。
 * 
 * @package Kode\AiAgent\Exception
 * 
 * 错误码范围: 1000-1999
 */
class PlatformException extends \RuntimeException implements AiAgentException
{
    protected const ERROR_CODES = [
        1001 => '连接失败',
        1002 => 'DNS 解析失败',
        1003 => 'SSL 握手失败',
        1004 => '响应解析失败',
        1005 => '服务端错误',
        1006 => '请求超时',
        1007 => '网络错误',
    ];

    public function __construct(
        string $message,
        private int $errorCode = 0,
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
     * 创建连接失败异常
     */
    public static function connectionFailed(string $url, ?\Throwable $previous = null): self
    {
        return new self(
            "连接失败: {$url}",
            1001,
            ['url' => $url],
            $previous
        );
    }

    /**
     * 创建响应解析失败异常
     */
    public static function responseParseFailed(string $reason, array $context = [], ?\Throwable $previous = null): self
    {
        return new self(
            "响应解析失败: {$reason}",
            1004,
            $context,
            $previous
        );
    }

    /**
     * 创建服务端错误异常
     */
    public static function serverError(int $statusCode, string $reason, array $context = []): self
    {
        return new self(
            "服务端错误 ({$statusCode}): {$reason}",
            1005,
            array_merge(['status_code' => $statusCode], $context)
        );
    }

    /**
     * 创建网络错误异常
     */
    public static function networkError(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "网络错误: {$reason}",
            1007,
            [],
            $previous
        );
    }
}
