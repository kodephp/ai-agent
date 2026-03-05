<?php

declare(strict_types=1);

namespace Kode\AiAgent\Exception;

/**
 * 连接异常
 * 
 * 当网络连接失败时抛出此异常。
 * 
 * @package Kode\AiAgent\Exception
 * 
 * 错误码范围: 1000-1999 (平台错误)
 */
class ConnectionException extends PlatformException
{
    /**
     * 创建 DNS 解析失败异常
     */
    public static function dnsFailed(string $host, ?\Throwable $previous = null): self
    {
        return new self(
            "DNS 解析失败: {$host}",
            1002,
            ['host' => $host],
            $previous
        );
    }

    /**
     * 创建 SSL 握手失败异常
     */
    public static function sslFailed(string $url, ?\Throwable $previous = null): self
    {
        return new self(
            "SSL 握手失败: {$url}",
            1003,
            ['url' => $url],
            $previous
        );
    }

    /**
     * 创建连接超时异常
     */
    public static function timeout(string $url, int $timeout, ?\Throwable $previous = null): self
    {
        return new self(
            "连接超时 ({$timeout}s): {$url}",
            1006,
            ['url' => $url, 'timeout' => $timeout],
            $previous
        );
    }

    /**
     * 创建连接被拒绝异常
     */
    public static function refused(string $url, ?\Throwable $previous = null): self
    {
        return new self(
            "连接被拒绝: {$url}",
            1008,
            ['url' => $url],
            $previous
        );
    }
}
