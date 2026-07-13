<?php

declare(strict_types=1);

namespace Kode\AiAgent\VideoGateway\Strategy;

use Kode\AiAgent\VideoGateway\VideoExpert;
use Kode\AiAgent\VideoGateway\VideoPriceTable;

/**
 * 成本感知路由策略
 *
 * 在能力满足的前提下，选择预估成本最低的专家；
 * 若设置了 max_cost，则只保留成本不超过上限的专家。
 *
 * @package Kode\AiAgent\VideoGateway\Strategy
 */
final class CostAwareVideoStrategy implements VideoRoutingStrategyInterface
{
    public function __construct(
        private readonly VideoPriceTable $priceTable,
    ) {}

    public function name(): string
    {
        return 'cost_aware';
    }

    public function select(
        array $experts,
        string $capability,
        ?float $maxCost = null,
        ?string $preferredModel = null,
        ?string $preferredPlatform = null,
    ): VideoExpert {
        if ($experts === []) {
            throw new \RuntimeException('没有可用的视频专家');
        }

        // 尊重偏好模型/平台（在成本感知前优先命中）
        if ($preferredModel !== null) {
            foreach ($experts as $expert) {
                if ($expert->model() === $preferredModel) {
                    return $expert;
                }
            }
        }
        if ($preferredPlatform !== null) {
            foreach ($experts as $expert) {
                if ($expert->platform() === $preferredPlatform) {
                    return $expert;
                }
            }
        }

        $candidates = $experts;
        if ($maxCost !== null) {
            $filtered = array_values(array_filter(
                $candidates,
                static fn(VideoExpert $e) => $e->estimateCost() <= $maxCost
            ));
            if ($filtered !== []) {
                $candidates = $filtered;
            }
        }

        usort($candidates, fn(VideoExpert $a, VideoExpert $b) =>
            $this->priceTable->estimate($a->model()) <=> $this->priceTable->estimate($b->model())
        );

        return $candidates[0];
    }
}
