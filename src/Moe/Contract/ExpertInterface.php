<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe\Contract;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};

/**
 * 专家接口
 *
 * MOE 架构中的"专家"代表一个可调用的 AI 适配器实例，
 * 它封装了平台、模型、配置等信息，并对外提供统一的调用接口。
 *
 * @package Kode\AiAgent\Moe\Contract
 */
interface ExpertInterface
{
    /**
     * 同步调用
     */
    #[\NoDiscard]
    public function send(PromptInterface $prompt, array $options = []): ResponseInterface;

    /**
     * 流式调用
     */
    #[\NoDiscard]
    public function stream(PromptInterface $prompt, array $options = []): \Generator;

    /**
     * 获取专家唯一标识
     */
    public function id(): string;

    /**
     * 获取平台名称
     */
    public function platform(): string;

    /**
     * 获取模型名称
     */
    public function model(): string;

    /**
     * 获取能力标签集合
     *
     * 返回专家擅长的能力列表，例如：['chat', 'code', 'vision', 'function_call']
     *
     * @return array<int, string>
     */
    public function capabilities(): array;

    /**
     * 获取优先级（数值越小优先级越高）
     */
    public function priority(): int;

    /**
     * 获取权重（用于加权随机）
     */
    public function weight(): float;

    /**
     * 是否健康可用
     */
    public function isHealthy(): bool;

    /**
     * 标记为不健康
     */
    public function markUnhealthy(string $reason = ''): void;

    /**
     * 恢复健康
     */
    public function markHealthy(): void;

    /**
     * 获取底层适配器
     */
    public function adapter(): AdapterInterface;
}
