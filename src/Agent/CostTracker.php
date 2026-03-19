<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

/**
 * 成本追踪器
 *
 * 追踪 Agent 的 Token 使用量和成本。
 *
 * @package Kode\AiAgent\Agent
 */
final class CostTracker
{
    private array $records = [];
    private array $totals = [
        'total_tokens' => 0,
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_cost' => 0,
        'request_count' => 0,
    ];

    private array $modelPrices = [
        'gpt-4o' => ['prompt' => 0.000005, 'completion' => 0.000015],
        'gpt-4-turbo' => ['prompt' => 0.00001, 'completion' => 0.00003],
        'gpt-3.5-turbo' => ['prompt' => 0.0000015, 'completion' => 0.000002],
        'claude-3-5-sonnet' => ['prompt' => 0.000003, 'completion' => 0.000015],
        'claude-3-opus' => ['prompt' => 0.000015, 'completion' => 0.000075],
        'claude-3-haiku' => ['prompt' => 0.00000025, 'completion' => 0.00000125],
        'deepseek-chat' => ['prompt' => 0.00000014, 'completion' => 0.00000028],
        'default' => ['prompt' => 0.000001, 'completion' => 0.000002],
    ];

    public function __construct(
        private string $currency = 'USD',
        private bool $enabled = true,
    ) {}

    public function track(
        string $model,
        int $promptTokens,
        int $completionTokens,
        array $metadata = []
    ): void {
        if (!$this->enabled) {
            return;
        }

        $cost = $this->calculateCost($model, $promptTokens, $completionTokens);

        $record = [
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'cost' => $cost,
            'timestamp' => microtime(true),
            'metadata' => $metadata,
        ];

        $this->records[] = $record;

        $this->totals['prompt_tokens'] += $promptTokens;
        $this->totals['completion_tokens'] += $completionTokens;
        $this->totals['total_tokens'] += $record['total_tokens'];
        $this->totals['total_cost'] += $cost;
        $this->totals['request_count']++;
    }

    public function calculateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        $prices = $this->modelPrices[$model] ?? $this->modelPrices['default'];

        return ($promptTokens * $prices['prompt'])
            + ($completionTokens * $prices['completion']);
    }

    public function setModelPrice(string $model, float $promptPrice, float $completionPrice): void
    {
        $this->modelPrices[$model] = [
            'prompt' => $promptPrice,
            'completion' => $completionPrice,
        ];
    }

    public function totals(): array
    {
        return $this->totals;
    }

    public function records(): array
    {
        return $this->records;
    }

    public function formattedCost(): string
    {
        return sprintf('%.6f %s', $this->totals['total_cost'], $this->currency);
    }

    public function formattedTokens(): string
    {
        $tokens = $this->totals['total_tokens'];

        if ($tokens < 1000) {
            return (string) $tokens;
        }

        if ($tokens < 1000000) {
            return sprintf('%.1fK', $tokens / 1000);
        }

        return sprintf('%.2fM', $tokens / 1000000);
    }

    public function reset(): void
    {
        $this->records = [];
        $this->totals = [
            'total_tokens' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_cost' => 0,
            'request_count' => 0,
        ];
    }

    public function summary(): array
    {
        return [
            'total_tokens' => $this->totals['total_tokens'],
            'total_cost' => $this->formattedCost(),
            'total_cost_raw' => $this->totals['total_cost'],
            'prompt_tokens' => $this->totals['prompt_tokens'],
            'completion_tokens' => $this->totals['completion_tokens'],
            'request_count' => $this->totals['request_count'],
            'avg_tokens_per_request' => $this->totals['request_count'] > 0
                ? round($this->totals['total_tokens'] / $this->totals['request_count'])
                : 0,
            'avg_cost_per_request' => $this->totals['request_count'] > 0
                ? $this->totals['total_cost'] / $this->totals['request_count']
                : 0,
        ];
    }

    public function toArray(): array
    {
        return [
            'summary' => $this->summary(),
            'records' => $this->records,
        ];
    }
}
