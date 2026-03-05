<?php

declare(strict_types=1);

namespace Kode\AiAgent\Exception;

/**
 * AI Agent 基础异常接口
 * 
 * 所有 AI Agent 异常必须实现此接口。
 * 
 * @package Kode\AiAgent\Exception
 */
interface AiAgentException extends \Throwable
{
    /**
     * 获取错误码
     *
     * @return int 错误码
     */
    public function errorCode(): int;

    /**
     * 获取错误上下文
     *
     * @return array 上下文数据
     */
    public function context(): array;
}
