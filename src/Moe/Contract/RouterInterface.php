<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe\Contract;

use Kode\AiAgent\Domain\Contract\{PromptInterface, ResponseInterface};
use Kode\AiAgent\Moe\RoutingContext;

/**
 * 路由器接口
 *
 * 路由器根据任务上下文（能力、预算、可用性等）选择最合适的专家来处理请求。
 *
 * @package Kode\AiAgent\Moe\Contract
 */
interface RouterInterface
{
    /**
     * 路由并执行同步请求
     *
     * @param PromptInterface $prompt 提示词
     * @param array{
     *     capability?: string,
     *     max_cost?: float,
     *     preferred_platform?: string,
     *     preferred_model?: string,
     *     stream?: bool,
     *     temperature?: float,
     *     max_tokens?: int,
     * } $options 选项
     */
    #[\NoDiscard]
    public function dispatch(PromptInterface $prompt, array $options = []): ResponseInterface;

    /**
     * 路由并执行流式请求
     *
     * @return \Generator<string>
     */
    #[\NoDiscard]
    public function stream(PromptInterface $prompt, array $options = []): \Generator;

    /**
     * 注册专家
     */
    public function registerExpert(ExpertInterface $expert): self;

    /**
     * 批量注册专家
     *
     * @param iterable<ExpertInterface> $experts
     */
    public function registerExperts(iterable $experts): self;

    /**
     * 选择专家（不实际执行）
     */
    public function select(RoutingContext $context): ExpertInterface;

    /**
     * 获取所有专家
     *
     * @return array<int, ExpertInterface>
     */
    public function experts(): array;

    /**
     * 获取路由统计
     */
    public function statistics(): array;
}
