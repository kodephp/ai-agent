<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe\Strategy;

use Kode\AiAgent\Moe\Contract\ExpertInterface;
use Kode\AiAgent\Moe\RoutingContext;

/**
 * 路由策略接口
 *
 * 不同的路由策略实现不同的专家选择算法。
 *
 * @package Kode\AiAgent\Moe\Strategy
 */
interface RoutingStrategyInterface
{
    /**
     * 从候选专家中选择一个
     *
     * @param array<int, ExpertInterface> $candidates 候选专家
     * @param RoutingContext $context 路由上下文
     * @return ExpertInterface 选中的专家
     */
    public function select(array $candidates, RoutingContext $context): ExpertInterface;

    /**
     * 策略名称
     */
    public function name(): string;
}
