<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe\Strategy;

use Kode\AiAgent\Moe\Contract\ExpertInterface;
use Kode\AiAgent\Moe\ModelPriceTable;
use Kode\AiAgent\Moe\RoutingContext;
use Kode\AiAgent\Token\ModelTokenEfficiency;

/**
 * Token 均衡路由策略
 *
 * 综合考虑能力匹配、模型 Token 效率、价格和成本预算，
 * 选择“等效 Token 消耗”最低的专家。
 *
 * 与传统 MoE 在单一模型内部做专家路由不同，
 * 本策略在网关层跨模型做路由，更适合“单 Key 多模型”场景。
 *
 * @package Kode\AiAgent\Moe\Strategy
 */
final class TokenBalancedStrategy implements RoutingStrategyInterface
{
    private ModelPriceTable $priceTable;
    private ModelTokenEfficiency $efficiency;

    public function __construct(
        ?ModelPriceTable $priceTable = null,
        ?ModelTokenEfficiency $efficiency = null,
    ) {
        $this->priceTable = $priceTable ?? new ModelPriceTable();
        $this->efficiency = $efficiency ?? new ModelTokenEfficiency();
    }

    public function select(array $candidates, RoutingContext $context): ExpertInterface
    {
        $filtered = $this->filterCandidates($candidates, $context);

        // 等效 Token 评分：越低越优先
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
        return 'token_balanced';
    }

    /**
     * 综合评分
     *
     * score = 等效成本指数 / 权重 + 优先级微调
     * 等效成本指数 = (prompt_price + completion_price * 0.5) * token_efficiency_index
     */
    private function score(ExpertInterface $expert, RoutingContext $context): float
    {
        $model = $expert->model();
        $text = $context->promptText;
        $language = $this->efficiency->detectLanguage($text);

        $efficiencyIndex = $this->efficiency->index($model, $language);
        $promptPrice = $this->priceTable->promptPrice($model);
        $completionPrice = $this->priceTable->completionPrice($model);

        // 假设典型请求：prompt 1000 tokens，completion 500 tokens
        $estimatedCost = $promptPrice + $completionPrice * 0.5;
        $equivalentCost = $estimatedCost * $efficiencyIndex;

        // 超过单次成本上限则大幅罚分
        if ($context->maxCost !== null && $estimatedCost > $context->maxCost) {
            $equivalentCost *= 100;
        }

        $weight = max(0.01, $expert->weight());
        $priority = $expert->priority();

        return ($equivalentCost / $weight) + $priority * 0.0000001;
    }

    /**
     * 候选专家过滤
     *
     * @param array<int, ExpertInterface> $candidates
     * @return array<int, ExpertInterface>
     */
    private function filterCandidates(array $candidates, RoutingContext $context): array
    {
        $filtered = $candidates;

        if ($context->capability !== null) {
            $matched = array_values(array_filter(
                $filtered,
                static fn(ExpertInterface $e) => in_array($context->capability, $e->capabilities(), true)
            ));
            if ($matched !== []) {
                $filtered = $matched;
            }
        }

        if ($context->preferredPlatform !== null) {
            $matched = array_values(array_filter(
                $filtered,
                static fn(ExpertInterface $e) => $e->platform() === $context->preferredPlatform
            ));
            if ($matched !== []) {
                $filtered = $matched;
            }
        }

        if ($context->preferredModel !== null) {
            $matched = array_values(array_filter(
                $filtered,
                static fn(ExpertInterface $e) => $e->model() === $context->preferredModel
            ));
            if ($matched !== []) {
                $filtered = $matched;
            }
        }

        return $filtered;
    }
}
