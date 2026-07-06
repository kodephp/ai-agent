<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Moe\Contract\ExpertInterface;

/**
 * 默认专家实现
 *
 * 封装一个具体的 AI 适配器，添加能力标签、优先级、权重、健康度等元数据。
 *
 * @package Kode\AiAgent\Moe
 *
 * @example
 * ```php
 * $expert = new Expert(
 *     $openaiAdapter,
 *     capabilities: ['chat', 'function_call'],
 *     priority: 10,
 *     weight: 1.0,
 * );
 * ```
 */
final class Expert implements ExpertInterface
{
    private bool $healthy = true;
    private ?string $unhealthyReason = null;
    private ?float $unhealthyUntil = null;

    /**
     * @param AdapterInterface $adapter 底层适配器
     * @param string|null $id 自定义专家 ID（默认使用 platform:model）
     * @param array<int, string> $capabilities 能力标签
     * @param int $priority 优先级（数值越小越高）
     * @param float $weight 权重（加权随机用）
     * @param int $unhealthyTtlSec 不健康状态持续时间（秒），0 表示永久
     */
    public function __construct(
        private readonly AdapterInterface $adapter,
        private readonly ?string $id = null,
        private readonly array $capabilities = ['chat'],
        private readonly int $priority = 100,
        private readonly float $weight = 1.0,
        private readonly int $unhealthyTtlSec = 60,
    ) {}

    #[\NoDiscard]
    public function send(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        return $this->adapter->send($prompt, $options);
    }

    #[\NoDiscard]
    public function stream(PromptInterface $prompt, array $options = []): \Generator
    {
        return $this->adapter->stream($prompt, $options);
    }

    public function id(): string
    {
        return $this->id ?? $this->platform() . ':' . $this->model();
    }

    public function platform(): string
    {
        return $this->adapter->name();
    }

    public function model(): string
    {
        $config = $this->adapterConfig();
        return (string) ($config['model'] ?? 'default');
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function weight(): float
    {
        return max(0.0, $this->weight);
    }

    public function isHealthy(): bool
    {
        if ($this->healthy) {
            return true;
        }

        // 检查是否到了恢复时间
        // unhealthyTtlSec = 0 表示永久不恢复
        if ($this->unhealthyUntil !== null && microtime(true) >= $this->unhealthyUntil) {
            $this->healthy = true;
            $this->unhealthyReason = null;
            $this->unhealthyUntil = null;
            return true;
        }

        return false;
    }

    public function markUnhealthy(string $reason = ''): void
    {
        $this->healthy = false;
        $this->unhealthyReason = $reason;
        // unhealthyTtlSec = 0 表示永不自愈（需手动 markHealthy）
        $this->unhealthyUntil = $this->unhealthyTtlSec > 0
            ? microtime(true) + $this->unhealthyTtlSec
            : null;
    }

    public function markHealthy(): void
    {
        $this->healthy = true;
        $this->unhealthyReason = null;
        $this->unhealthyUntil = null;
    }

    public function adapter(): AdapterInterface
    {
        return $this->adapter;
    }

    /**
     * 获取不健康原因
     */
    public function unhealthyReason(): ?string
    {
        return $this->unhealthyReason;
    }

    /**
     * 读取适配器配置（兼容不同实现）
     */
    private function adapterConfig(): array
    {
        $adapter = $this->adapter;
        if (method_exists($adapter, 'config')) {
            $config = $adapter->config();
            if (is_array($config)) {
                return $config;
            }
        }

        $ref = new \ReflectionObject($adapter);
        foreach ($ref->getProperties() as $prop) {
            $prop->setAccessible(true);
            if ($prop->getName() === 'config') {
                $value = $prop->getValue($adapter);
                if (is_array($value)) {
                    return $value;
                }
            }
        }

        return [];
    }
}
