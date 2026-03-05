<?php

declare(strict_types=1);

namespace Kode\AiAgent\Exception;

/**
 * 输入验证异常
 * 
 * 当输入验证失败时抛出此异常。
 * 
 * @package Kode\AiAgent\Exception
 * 
 * 错误码范围: 1-999 (客户端错误)
 */
class InvalidInputException extends \InvalidArgumentException implements AiAgentException
{
    public function __construct(
        string $message,
        private int $errorCode = 1,
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
     * 创建空消息异常
     */
    public static function emptyMessage(): self
    {
        return new self(
            "消息不能为空",
            1,
            ['field' => 'message']
        );
    }

    /**
     * 创建消息过长异常
     */
    public static function messageTooLong(int $length, int $maxLength): self
    {
        return new self(
            "消息长度超限: {$length} > {$maxLength}",
            2,
            ['length' => $length, 'max_length' => $maxLength]
        );
    }

    /**
     * 创建无效参数异常
     */
    public static function invalidParameter(string $parameter, string $reason): self
    {
        return new self(
            "无效参数 {$parameter}: {$reason}",
            3,
            ['parameter' => $parameter, 'reason' => $reason]
        );
    }

    /**
     * 创建缺少必填参数异常
     */
    public static function missingRequired(string $parameter): self
    {
        return new self(
            "缺少必填参数: {$parameter}",
            4,
            ['parameter' => $parameter]
        );
    }
}
