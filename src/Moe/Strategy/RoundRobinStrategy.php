<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe\Strategy;

use Kode\AiAgent\Moe\Contract\ExpertInterface;
use Kode\AiAgent\Moe\RoutingContext;

/**
 * 轮询路由策略
 *
 * 按顺序依次选择专家，负载均衡。
 *
 * @package Kode\AiAgent\Moe\Strategy
 */
final class RoundRobinStrategy implements RoutingStrategyInterface
{
    private int $cursor = 0;

    public function select(array $candidates, RoutingContext $context): ExpertInterface
    {
        if ($candidates === []) {
            throw new \RuntimeException('没有可用的专家');
        }

        $expert = $candidates[$this->cursor % count($candidates)];
        $this->cursor++;
        return $expert;
    }

    public function name(): string
    {
        return 'round_robin';
    }
}
