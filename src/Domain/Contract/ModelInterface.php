<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * 模型接口
 * 
 * 定义 AI 模型的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 */
interface ModelInterface
{
    /**
     * 获取模型标识
     *
     * @return string 模型标识
     */
    public function id(): string;

    /**
     * 获取模型名称
     *
     * @return string 模型名称
     */
    public function name(): string;

    /**
     * 获取提供商
     *
     * @return string 提供商
     */
    public function provider(): string;

    /**
     * 获取最大 Token 数
     *
     * @return int 最大 Token 数
     */
    public function maxTokens(): int;

    /**
     * 检查是否支持多模态
     *
     * @return bool 是否支持
     */
    public function supportsMultimodal(): bool;

    /**
     * 检查是否支持流式
     *
     * @return bool 是否支持
     */
    public function supportsStreaming(): bool;
}
