<?php

declare(strict_types=1);

namespace Kode\AiAgent\Resilience;

/**
 * 健康检查器
 *
 * 主动探测专家（AI 平台）的可用性，避免冷启动时调用不可用服务。
 *
 * @package Kode\AiAgent\Resilience
 */
final class HealthChecker
{
    /** @var array<string, array{healthy: bool, last_check: float, latency_ms: float, error: ?string}> */
    private array $status = [];

    /**
     * @param int $cacheTtlSec 检查结果缓存时间（秒）
     */
    public function __construct(
        private readonly int $cacheTtlSec = 30,
    ) {}

    /**
     * 检查健康（带结果缓存）
     *
     * @param string $target 目标标识（通常是 expert id）
     * @param callable $probe 探测回调：执行轻量级请求，返回是否成功
     */
    public function check(string $target, callable $probe): bool
    {
        $now = microtime(true);
        $existing = $this->status[$target] ?? null;

        if ($existing !== null && ($now - $existing['last_check']) < $this->cacheTtlSec) {
            return $existing['healthy'];
        }

        $start = microtime(true);
        try {
            $probe();
            $latency = (microtime(true) - $start) * 1000;
            $this->status[$target] = [
                'healthy' => true,
                'last_check' => $now,
                'latency_ms' => $latency,
                'error' => null,
            ];
            return true;
        } catch (\Throwable $e) {
            $this->status[$target] = [
                'healthy' => false,
                'last_check' => $now,
                'latency_ms' => 0.0,
                'error' => $e->getMessage(),
            ];
            return false;
        }
    }

    /**
     * 获取缓存的健康状态（不实际探测）
     */
    public function statusOf(string $target): ?array
    {
        return $this->status[$target] ?? null;
    }

    /**
     * 获取所有目标的状态
     */
    public function all(): array
    {
        return $this->status;
    }

    /**
     * 清除指定目标的缓存
     */
    public function forget(string $target): void
    {
        unset($this->status[$target]);
    }

    /**
     * 清除全部
     */
    public function flush(): void
    {
        $this->status = [];
    }
}
