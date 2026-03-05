<?php

declare(strict_types=1);

namespace Kode\AiAgent\Exception;

/**
 * 无效响应异常
 * 
 * 当响应格式错误或无法解析时抛出此异常。
 * 
 * @package Kode\AiAgent\Exception
 * 
 * 错误码范围: 1000-1999 (平台错误)
 */
class InvalidResponseException extends PlatformException
{
    /**
     * 创建 JSON 解析失败异常
     */
    public static function jsonFailed(string $reason, array $context = [], ?\Throwable $previous = null): self
    {
        return new self(
            "JSON 解析失败: {$reason}",
            1004,
            array_merge(['reason' => $reason], $context),
            $previous
        );
    }

    /**
     * 创建缺少必要字段异常
     */
    public static function missingField(string $field, array $context = []): self
    {
        return new self(
            "响应缺少必要字段: {$field}",
            1009,
            array_merge(['field' => $field], $context)
        );
    }

    /**
     * 创建格式无效异常
     */
    public static function invalidFormat(string $expected, string $actual, array $context = []): self
    {
        return new self(
            "响应格式无效，期望 {$expected}，实际 {$actual}",
            1010,
            array_merge(['expected' => $expected, 'actual' => $actual], $context)
        );
    }

    /**
     * 创建空响应异常
     */
    public static function emptyResponse(array $context = []): self
    {
        return new self(
            "响应内容为空",
            1011,
            $context
        );
    }
}
