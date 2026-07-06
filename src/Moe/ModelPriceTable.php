<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe;

/**
 * 模型价格表
 *
 * 统一管理各模型的 Token 单价（每 1000 tokens，美元），
 * 用于成本估算、Token 预算控制和路由决策。
 *
 * 价格数据来源：各平台官方公开定价（2025-2026）
 *
 * @package Kode\AiAgent\Moe
 */
final class ModelPriceTable
{
    /**
     * 价格表：model => ['prompt' => $/1k, 'completion' => $/1k]
     *
     * @var array<string, array{prompt: float, completion: float}>
     */
    private const PRICES = [
        // OpenAI
        'gpt-4o' => ['prompt' => 0.0025, 'completion' => 0.01],
        'gpt-4o-mini' => ['prompt' => 0.00015, 'completion' => 0.0006],
        'gpt-4-turbo' => ['prompt' => 0.01, 'completion' => 0.03],
        'gpt-3.5-turbo' => ['prompt' => 0.0005, 'completion' => 0.0015],
        'o1-preview' => ['prompt' => 0.015, 'completion' => 0.06],
        'o1-mini' => ['prompt' => 0.003, 'completion' => 0.012],
        'gpt-image-1' => ['prompt' => 0.01, 'completion' => 0.04],

        // Anthropic
        'claude-3-5-sonnet' => ['prompt' => 0.003, 'completion' => 0.015],
        'claude-3-5-haiku' => ['prompt' => 0.0008, 'completion' => 0.004],
        'claude-3-opus' => ['prompt' => 0.015, 'completion' => 0.075],
        'claude-3-haiku' => ['prompt' => 0.00025, 'completion' => 0.00125],

        // DeepSeek
        'deepseek-chat' => ['prompt' => 0.00014, 'completion' => 0.00028],
        'deepseek-reasoner' => ['prompt' => 0.00055, 'completion' => 0.00219],

        // 阿里云通义
        'qwen-turbo' => ['prompt' => 0.0003, 'completion' => 0.0006],
        'qwen-plus' => ['prompt' => 0.0008, 'completion' => 0.002],
        'qwen-max' => ['prompt' => 0.002, 'completion' => 0.006],
        'qwen-long' => ['prompt' => 0.0005, 'completion' => 0.001],

        // 百度文心
        'ernie-4.0' => ['prompt' => 0.0012, 'completion' => 0.0012],
        'ernie-3.5' => ['prompt' => 0.0008, 'completion' => 0.0008],
        'ernie-speed' => ['prompt' => 0, 'completion' => 0],

        // 腾讯混元
        'hunyuan-pro' => ['prompt' => 0.001, 'completion' => 0.001],
        'hunyuan-standard' => ['prompt' => 0.0007, 'completion' => 0.0007],

        // 讯飞星火
        'spark-v4.0' => ['prompt' => 0.0009, 'completion' => 0.0009],
        'spark-v3.5' => ['prompt' => 0.0005, 'completion' => 0.0005],

        // Google Gemini
        'gemini-1.5-pro' => ['prompt' => 0.00125, 'completion' => 0.005],
        'gemini-1.5-flash' => ['prompt' => 0.000075, 'completion' => 0.0003],
        'gemini-2.0-flash' => ['prompt' => 0.0001, 'completion' => 0.0004],
    ];

    /**
     * @var array<string, array{prompt: float, completion: float}>
     */
    private array $customPrices = [];

    /**
     * 获取模型的 prompt 价格（每 1k tokens，美元）
     */
    public function promptPrice(string $model): float
    {
        $table = $this->customPrices + self::PRICES;
        return $table[$model]['prompt'] ?? $table['default']['prompt'] ?? 0.001;
    }

    /**
     * 获取模型的 completion 价格（每 1k tokens，美元）
     */
    public function completionPrice(string $model): float
    {
        $table = $this->customPrices + self::PRICES;
        return $table[$model]['completion'] ?? $table['default']['completion'] ?? 0.002;
    }

    /**
     * 估算单次请求成本
     */
    public function estimate(string $model, int $promptTokens, int $completionTokens): float
    {
        return ($promptTokens / 1000.0) * $this->promptPrice($model)
            + ($completionTokens / 1000.0) * $this->completionPrice($model);
    }

    /**
     * 设置自定义价格
     */
    public function setPrice(string $model, float $promptPrice, float $completionPrice): void
    {
        $this->customPrices[$model] = [
            'prompt' => max(0.0, $promptPrice),
            'completion' => max(0.0, $completionPrice),
        ];
    }

    /**
     * 是否存在该模型的价格
     */
    public function has(string $model): bool
    {
        return isset(self::PRICES[$model]) || isset($this->customPrices[$model]);
    }

    /**
     * 获取所有支持的模型
     *
     * @return array<int, string>
     */
    public function models(): array
    {
        return array_unique(array_merge(array_keys(self::PRICES), array_keys($this->customPrices)));
    }
}
