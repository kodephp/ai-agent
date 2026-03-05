<?php

declare(strict_types=1);

namespace Kode\AiAgent\Exception;

/**
 * 工具执行异常
 * 
 * 当工具执行失败时抛出此异常。
 * 错误码范围：4000-4999
 * 
 * @package Kode\AiAgent\Exception
 * 
 * @example
 * ```php
 * throw ToolExecutionException::notFound('calculator');
 * throw ToolExecutionException::executionFailed('calculator', 'Division by zero');
 * ```
 */
final class ToolExecutionException extends \RuntimeException implements AiAgentException
{
    public const CODE_NOT_FOUND = 4001;
    public const CODE_EXECUTION_FAILED = 4002;
    public const CODE_INVALID_ARGUMENTS = 4003;
    public const CODE_TIMEOUT = 4004;

    public function __construct(
        string $message,
        private int $errorCode,
        private array $context = [],
        ?\Throwable $previous = null,
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
     * 工具不存在
     */
    public static function notFound(string $toolName): self
    {
        return new self(
            "工具不存在: {$toolName}",
            self::CODE_NOT_FOUND,
            ['tool' => $toolName]
        );
    }

    /**
     * 工具执行失败
     */
    public static function executionFailed(string $toolName, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "工具执行失败: {$toolName} - {$reason}",
            self::CODE_EXECUTION_FAILED,
            ['tool' => $toolName, 'reason' => $reason],
            $previous
        );
    }

    /**
     * 参数无效
     */
    public static function invalidArguments(string $toolName, array $errors): self
    {
        return new self(
            "工具参数无效: {$toolName}",
            self::CODE_INVALID_ARGUMENTS,
            ['tool' => $toolName, 'errors' => $errors]
        );
    }

    /**
     * 执行超时
     */
    public static function timeout(string $toolName, int $timeout): self
    {
        return new self(
            "工具执行超时: {$toolName} (超过 {$timeout} 秒)",
            self::CODE_TIMEOUT,
            ['tool' => $toolName, 'timeout' => $timeout]
        );
    }
}
