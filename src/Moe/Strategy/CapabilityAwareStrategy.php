<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe\Strategy;

use Kode\AiAgent\Moe\Contract\ExpertInterface;
use Kode\AiAgent\Moe\RoutingContext;

/**
 * 能力感知路由策略
 *
 * 优先选择具备所需能力且优先级最高的专家，
 * 相同优先级时按权重加权随机分布。
 *
 * @package Kode\AiAgent\Moe\Strategy
 */
final class CapabilityAwareStrategy implements RoutingStrategyInterface
{
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
                // 没有具备该能力的专家，回退到全部候选
                $filtered = $candidates;
            }
        }

        // 2. 偏好平台过滤
        if ($context->preferredPlatform !== null) {
            $platformMatched = array_values(array_filter(
                $filtered,
                static fn(ExpertInterface $e) => $e->platform() === $context->preferredPlatform
            ));
            if ($platformMatched !== []) {
                $filtered = $platformMatched;
            }
        }

        // 3. 偏好模型过滤
        if ($context->preferredModel !== null) {
            $modelMatched = array_values(array_filter(
                $filtered,
                static fn(ExpertInterface $e) => $e->model() === $context->preferredModel
            ));
            if ($modelMatched !== []) {
                $filtered = $modelMatched;
            }
        }

        // 4. 按优先级排序（数值越小越优先）
        usort($filtered, static fn(ExpertInterface $a, ExpertInterface $b) => $a->priority() <=> $b->priority());

        // 5. 取优先级最高的一批，按权重随机选一个
        $topPriority = $filtered[0]->priority();
        $topTier = array_values(array_filter(
            $filtered,
            static fn(ExpertInterface $e) => $e->priority() === $topPriority
        ));

        if (count($topTier) === 1) {
            return $topTier[0];
        }

        return $this->weightedRandom($topTier);
    }

    public function name(): string
    {
        return 'capability_aware';
    }

    /**
     * 加权随机选择
     *
     * @param array<int, ExpertInterface> $candidates
     */
    private function weightedRandom(array $candidates): ExpertInterface
    {
        $totalWeight = 0.0;
        foreach ($candidates as $expert) {
            $totalWeight += $expert->weight();
        }

        if ($totalWeight <= 0.0) {
            return $candidates[array_rand($candidates)];
        }

        $target = mt_rand() / mt_getrandmax() * $totalWeight;
        $cumulative = 0.0;
        foreach ($candidates as $expert) {
            $cumulative += $expert->weight();
            if ($cumulative >= $target) {
                return $expert;
            }
        }

        return $candidates[array_key_last($candidates)];
    }
}
