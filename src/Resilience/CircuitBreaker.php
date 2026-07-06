<?php

declare(strict_types=1);

namespace Kode\AiAgent\Resilience;

/**
 * 熔断器（Circuit Breaker）
 *
 * 防止 AI 平台持续故障时反复发起失败请求，自动降级到其他专家。
 *
 * 三种状态：
 * - CLOSED：正常状态，请求通过
 * - OPEN：熔断状态，请求直接失败
 * - HALF_OPEN：半开状态，允许少量试探请求
 *
 * 状态转换：
 * - CLOSED → OPEN：连续失败次数达到阈值
 * - OPEN → HALF_OPEN：冷却时间到期
 * - HALF_OPEN → CLOSED：试探请求成功
 * - HALF_OPEN → OPEN：试探请求失败
 *
 * @package Kode\AiAgent\Resilience
 *
 * @example
 * ```php
 * $breaker = new CircuitBreaker(
 *     failureThreshold: 5,
 *     cooldownSeconds: 60,
 * );
 *
 * $result = $breaker->call(fn() => $adapter->send($prompt));
 * ```
 */
final class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private int $successCount = 0;
    private float $openedAt = 0.0;
    private int $halfOpenSuccess = 0;
    private int $halfOpenFailure = 0;

    /**
     * @param int $failureThreshold 触发熔断的连续失败次数
     * @param int $cooldownSeconds 熔断持续时间（秒）
     * @param int $halfOpenMaxAttempts 半开状态最大试探次数
     * @param int $halfOpenSuccessThreshold 半开状态恢复所需的连续成功次数
     * @param callable|null $onStateChange 状态变化回调
     */
    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds = 60,
        private readonly int $halfOpenMaxAttempts = 3,
        private readonly int $halfOpenSuccessThreshold = 2,
        private $onStateChange = null,
    ) {}

    /**
     * 通过熔断器执行操作
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws CircuitOpenException 当熔断器打开时
     */
    public function call(callable $operation): mixed
    {
        $this->refreshState();

        if ($this->state === self::STATE_OPEN) {
            throw new CircuitOpenException(sprintf(
                '熔断器已打开，剩余冷却时间 %d 秒',
                $this->cooldownRemaining()
            ));
        }

        try {
            $result = $operation();
            $this->recordSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    /**
     * 获取当前状态
     */
    public function state(): string
    {
        $this->refreshState();
        return $this->state;
    }

    /**
     * 是否允许请求通过
     */
    public function allowsRequest(): bool
    {
        $this->refreshState();
        return $this->state !== self::STATE_OPEN;
    }

    /**
     * 强制重置
     */
    public function reset(): void
    {
        $previous = $this->state;
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->halfOpenSuccess = 0;
        $this->halfOpenFailure = 0;
        $this->openedAt = 0.0;
        $this->notifyStateChange($previous, self::STATE_CLOSED);
    }

    /**
     * 获取状态信息
     */
    public function status(): array
    {
        return [
            'state' => $this->state(),
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
            'cooldown_remaining' => $this->cooldownRemaining(),
            'opened_at' => $this->openedAt > 0 ? $this->openedAt : null,
        ];
    }

    /**
     * 刷新状态（OPEN → HALF_OPEN 当冷却到期）
     */
    private function refreshState(): void
    {
        if ($this->state === self::STATE_OPEN
            && microtime(true) - $this->openedAt >= $this->cooldownSeconds
        ) {
            $this->transition(self::STATE_HALF_OPEN);
        }
    }

    private function recordSuccess(): void
    {
        $this->successCount++;

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->halfOpenSuccess++;
            $this->halfOpenFailure = 0;
            if ($this->halfOpenSuccess >= $this->halfOpenSuccessThreshold) {
                $this->transition(self::STATE_CLOSED);
                $this->failureCount = 0;
            }
        } else {
            $this->failureCount = 0;
        }
    }

    private function recordFailure(): void
    {
        $this->failureCount++;

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->halfOpenFailure++;
            $this->halfOpenSuccess = 0;
            $attempts = $this->halfOpenFailure + $this->halfOpenSuccess;
            if ($this->halfOpenFailure >= 1 || $attempts >= $this->halfOpenMaxAttempts) {
                $this->transition(self::STATE_OPEN);
            }
            return;
        }

        if ($this->failureCount >= $this->failureThreshold) {
            $this->transition(self::STATE_OPEN);
        }
    }

    private function transition(string $newState): void
    {
        $previous = $this->state;
        $this->state = $newState;
        $this->halfOpenSuccess = 0;
        $this->halfOpenFailure = 0;

        if ($newState === self::STATE_OPEN) {
            $this->openedAt = microtime(true);
        }

        $this->notifyStateChange($previous, $newState);
    }

    private function notifyStateChange(string $from, string $to): void
    {
        if ($this->onStateChange !== null) {
            ($this->onStateChange)($from, $to, $this);
        }
    }

    private function cooldownRemaining(): int
    {
        if ($this->state !== self::STATE_OPEN) {
            return 0;
        }
        $elapsed = microtime(true) - $this->openedAt;
        return max(0, (int) ceil($this->cooldownSeconds - $elapsed));
    }
}
