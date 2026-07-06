<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe\Strategy;

use Kode\AiAgent\Moe\Contract\ExpertInterface;
use Kode\AiAgent\Moe\ModelPriceTable;
use Kode\AiAgent\Moe\RoutingContext;

/**
 * 成本感知路由策略
 *
 * 在能力匹配的基础上，优先选择成本最低的专家，
 * 兼顾 Token 预算和单次成本上限。
 *
 * @package Kode\AiAgent\Moe\Strategy
 */
final class CostAwareStrategy implements RoutingStrategyInterface
{
    public function __construct(
        private readonly ModelPriceTable $priceTable,
    ) {}

    public function select(array $candidates, RoutingContext $context): ExpertInterface
    {
        $filtered = $candidates;

        // 1. 能力过滤
        if ($context->capability !== null) {
            $filtered = array_values(array_filter(
                $filtered,
                static fn(ExpertInterface $e) => in_array($context->capability, $e->capabilities(), true)
            ));
            if ($filtered === []) {
                $filtered = $candidates;
            }
        }

        // 2. 偏好优先
        if ($context->preferredPlatform !== null) {
            $platformMatched = array_values(array_filter(
                $filtered,
                static fn(ExpertInterface $e) => $e->platform() === $context->preferredPlatform
            ));
            if ($platformMatched !== []) {
                $filtered = $platformMatched;
            }
        }

        if ($context->preferredModel !== null) {
            $modelMatched = array_values(array_filter(
                $filtered,
                static fn(ExpertInterface $e) => $e->model() === $context->preferredModel
            ));
            if ($modelMatched !== []) {
                $filtered = $modelMatched;
            }
        }

        // 3. 按综合成本得分排序（成本越低 + 权重越高 + 优先级越低，得分越低）
        $scored = array_map(
            fn(ExpertInterface $e) => [
                'expert' => $e,
                'score' => $this->score($e, $context),
            ],
            $filtered
        );

        usort($scored, static fn($a, $b) => $a['score'] <=> $b['score']);
        return $scored[0]['expert'];
    }

    public function name(): string
    {
        return 'cost_aware';
    }

    /**
     * 综合评分：成本越低得分越低，权重越高得分越低
     */
    private function score(ExpertInterface $expert, RoutingContext $context): float
    {
        $model = $expert->model();
        $promptPrice = $this->priceTable->promptPrice($model);
        $completionPrice = $this->priceTable->completionPrice($model);

        // 假设 prompt=1000, completion=500 的典型请求计算成本
        $estimatedCost = $promptPrice + $completionPrice * 0.5;

        // 超过单次成本上限的专家加分（推迟选择）
        if ($context->maxCost !== null && $estimatedCost > $context->maxCost) {
            $estimatedCost *= 100;
        }

        // 权重高的得分低（更倾向被选中）
        $weight = max(0.01, $expert->weight());

        // cost_aware 策略：成本权重主导（优先级仅作 tie-breaker）
        return ($estimatedCost / $weight) + $expert->priority() * 0.0000001;
    }
}
