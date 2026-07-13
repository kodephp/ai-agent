<?php

declare(strict_types=1);

namespace Kode\AiAgent\VideoGateway\Strategy;

use Kode\AiAgent\VideoGateway\VideoExpert;

/**
 * 轮询路由策略
 *
 * 在能力满足的专家之间轮流分配，实现负载均衡。
 *
 * @package Kode\AiAgent\VideoGateway\Strategy
 */
final class RoundRobinVideoStrategy implements VideoRoutingStrategyInterface
{
    private int $index = 0;

    public function name(): string
    {
        return 'round_robin';
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

        // 偏好命中优先
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

        $count = count($experts);
        $expert = $experts[$this->index % $count];
        $this->index++;

        return $expert;
    }
}
