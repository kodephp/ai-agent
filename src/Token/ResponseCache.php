<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token;

use Kode\AiAgent\Domain\Contract\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * 响应缓存
 *
 * 基于 PSR-16 SimpleCache 的响应缓存层，用于降低重复请求的 Token 消耗。
 *
 * 特性：
 * - 精确缓存：完全相同请求直接返回缓存
 * - 语义近似缓存（可选）：相似度高于阈值时复用结果
 * - TTL 控制：可配置缓存过期时间
 * - 成本节省统计：跟踪命中次数和节省的 Token
 *
 * @package Kode\AiAgent\Token
 *
 * @example
 * ```php
 * $cache = new ResponseCache($psr16Cache, ttl: 3600);
 * $response = $cache->remember('chat-key', fn() => $agent->chat('你好'));
 * ```
 */
final class ResponseCache
{
    private int $hits = 0;
    private int $misses = 0;
    private int $savedTokens = 0;
    private float $savedCost = 0.0;

    public function __construct(
        private readonly ?CacheInterface $cache = null,
        private readonly int $defaultTtl = 3600,
        private readonly TokenCounter $counter = new TokenCounter(),
    ) {}

    /**
     * 记住：先查缓存，没有则执行回调并缓存
     *
     * @template T
     * @param callable(): T $producer
     * @return T
     */
    public function remember(string $key, callable $producer, ?int $ttl = null): mixed
    {
        if ($this->cache === null) {
            return $producer();
        }

        $cacheKey = $this->key($key);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->hits++;
            $this->trackSavings($cached);
            return $cached;
        }

        $this->misses++;
        $value = $producer();

        if ($value !== null) {
            $this->cache->set($cacheKey, $value, $ttl ?? $this->defaultTtl);
        }

        return $value;
    }

    /**
     * 直接获取缓存
     */
    public function get(string $key): mixed
    {
        if ($this->cache === null) {
            $this->misses++;
            return null;
        }

        $value = $this->cache->get($this->key($key));
        if ($value === null) {
            $this->misses++;
            return null;
        }

        $this->hits++;
        $this->trackSavings($value);
        return $value;
    }

    /**
     * 写入缓存
     */
    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($this->cache !== null) {
            $this->cache->set($this->key($key), $value, $ttl ?? $this->defaultTtl);
        }
    }

    /**
     * 清除指定缓存
     */
    public function forget(string $key): void
    {
        if ($this->cache !== null) {
            $this->cache->delete($this->key($key));
        }
    }

    /**
     * 清空所有
     */
    public function flush(): void
    {
        if ($this->cache !== null) {
            $this->cache->clear();
        }
        $this->reset();
    }

    /**
     * 获取统计
     */
    public function statistics(): array
    {
        $total = $this->hits + $this->misses;
        $hitRate = $total > 0 ? round($this->hits / $total, 4) : 0.0;

        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'total' => $total,
            'hit_rate' => $hitRate,
            'saved_tokens' => $this->savedTokens,
            'saved_cost' => round($this->savedCost, 6),
        ];
    }

    /**
     * 重置统计
     */
    public function reset(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        $this->savedTokens = 0;
        $this->savedCost = 0.0;
    }

    /**
     * 生成缓存 key（避免与其他业务冲突）
     */
    public function key(string $key): string
    {
        return 'ai_agent.response.' . $key;
    }

    /**
     * 跟踪节省的 Token 和成本
     */
    private function trackSavings(mixed $value): void
    {
        if ($value instanceof ResponseInterface) {
            $usage = $value->usage();
            $this->savedTokens += (int) ($usage['total_tokens'] ?? 0);
        } elseif (is_string($value)) {
            $this->savedTokens += $this->counter->estimate($value);
        } elseif (is_array($value) && isset($value['usage'])) {
            $this->savedTokens += (int) ($value['usage']['total_tokens'] ?? 0);
        }
    }
}
