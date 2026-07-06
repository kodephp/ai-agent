<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe;

/**
 * Token 预算管理器
 *
 * 跟踪和限制 Token 消耗：支持单次 / 每分钟 / 每天 / 每月等维度的预算控制，
 * 帮助用户控制成本并平衡多模型间的资源分配。
 *
 * @package Kode\AiAgent\Moe
 *
 * @example
 * ```php
 * $budget = new TokenBudget(
 *     perMinute: 100_000,  // 每分钟 10 万 tokens
 *     perDay: 10_000_000,  // 每天 1000 万 tokens
 *     perMonthCost: 100.0, // 每月 100 美元
 * );
 *
 * if (!$budget->canConsume(5000, 2000)) {
 *     throw new RateLimitException('Token 预算已耗尽');
 * }
 *
 * $budget->consume(5000, 2000, 0.05);
 * ```
 */
final class TokenBudget
{
    private int $promptTokens = 0;
    private int $completionTokens = 0;
    private float $totalCost = 0.0;

    /** @var array<int, array{tokens: int, reset: int}> */
    private array $minuteBuckets = [];

    /** @var array<int, array{tokens: int, cost: float, reset: int}> */
    private array $dayBuckets = [];

    public function __construct(
        private readonly ?int $perMinute = null,
        private readonly ?int $perDay = null,
        private readonly ?float $perMonthCost = null,
    ) {}

    /**
     * 检查是否可以消费指定 Token
     */
    public function canConsume(int $promptTokens, int $completionTokens = 0, float $estimatedCost = 0.0): bool
    {
        $total = $promptTokens + $completionTokens;

        // 检查每分钟
        if ($this->perMinute !== null) {
            $this->cleanExpiredBuckets();
            $minuteUsed = $this->sumBucket($this->minuteBuckets);
            if ($minuteUsed + $total > $this->perMinute) {
                return false;
            }
        }

        // 检查每天
        if ($this->perDay !== null) {
            $this->cleanExpiredBuckets();
            $dayUsed = $this->sumBucket($this->dayBuckets);
            if ($dayUsed + $total > $this->perDay) {
                return false;
            }
        }

        // 检查每月成本
        if ($this->perMonthCost !== null) {
            $this->cleanExpiredBuckets();
            $monthCost = array_sum(array_column($this->dayBuckets, 'cost'));
            if ($monthCost + $estimatedCost > $this->perMonthCost) {
                return false;
            }
        }

        return true;
    }

    /**
     * 消费 Token
     *
     * @throws \RuntimeException 当预算不足时
     */
    public function consume(int $promptTokens, int $completionTokens = 0, float $cost = 0.0): void
    {
        if (!$this->canConsume($promptTokens, $completionTokens, $cost)) {
            throw new \RuntimeException(sprintf(
                'Token 预算不足: 需要 %d tokens, 成本 $%.4f',
                $promptTokens + $completionTokens,
                $cost
            ));
        }

        $this->promptTokens += $promptTokens;
        $this->completionTokens += $completionTokens;
        $this->totalCost += $cost;

        $now = time();
        $minute = (int) floor($now / 60) * 60;
        $day = (int) floor($now / 86400) * 86400;

        if (!isset($this->minuteBuckets[$minute])) {
            $this->minuteBuckets[$minute] = ['tokens' => 0, 'reset' => $minute + 60];
        }
        $this->minuteBuckets[$minute]['tokens'] += $promptTokens + $completionTokens;

        if (!isset($this->dayBuckets[$day])) {
            $this->dayBuckets[$day] = ['tokens' => 0, 'cost' => 0.0, 'reset' => $day + 86400];
        }
        $this->dayBuckets[$day]['tokens'] += $promptTokens + $completionTokens;
        $this->dayBuckets[$day]['cost'] += $cost;
    }

    /**
     * 获取总消耗
     */
    public function totals(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->promptTokens + $this->completionTokens,
            'total_cost' => $this->totalCost,
        ];
    }

    /**
     * 获取剩余预算
     */
    public function remaining(): array
    {
        $this->cleanExpiredBuckets();

        $result = [];

        if ($this->perMinute !== null) {
            $used = $this->sumBucket($this->minuteBuckets);
            $result['per_minute'] = [
                'limit' => $this->perMinute,
                'used' => $used,
                'remaining' => max(0, $this->perMinute - $used),
            ];
        }

        if ($this->perDay !== null) {
            $used = $this->sumBucket($this->dayBuckets);
            $result['per_day'] = [
                'limit' => $this->perDay,
                'used' => $used,
                'remaining' => max(0, $this->perDay - $used),
            ];
        }

        if ($this->perMonthCost !== null) {
            $monthCost = array_sum(array_column($this->dayBuckets, 'cost'));
            $result['per_month_cost'] = [
                'limit' => $this->perMonthCost,
                'used' => round($monthCost, 6),
                'remaining' => round(max(0.0, $this->perMonthCost - $monthCost), 6),
            ];
        }

        return $result;
    }

    /**
     * 重置所有计数
     */
    public function reset(): void
    {
        $this->promptTokens = 0;
        $this->completionTokens = 0;
        $this->totalCost = 0.0;
        $this->minuteBuckets = [];
        $this->dayBuckets = [];
    }

    /**
     * 清理过期桶
     */
    private function cleanExpiredBuckets(): void
    {
        $now = time();

        foreach ($this->minuteBuckets as $key => $bucket) {
            if ($bucket['reset'] <= $now) {
                unset($this->minuteBuckets[$key]);
            }
        }

        foreach ($this->dayBuckets as $key => $bucket) {
            if ($bucket['reset'] <= $now) {
                unset($this->dayBuckets[$key]);
            }
        }
    }

    /**
     * @param array<int, array{tokens: int, reset: int}> $buckets
     */
    private function sumBucket(array $buckets): int
    {
        return (int) array_sum(array_column($buckets, 'tokens'));
    }
}
