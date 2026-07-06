<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token;

/**
 * Token 消耗平衡器
 *
 * 在“单 Key 多模型”场景下，不同模型的 Token 单价和 Token 效率都不同。
 * 本类提供跨模型消耗的统一度量：
 * - 把各模型实际 Token 归一化为“基准 Token”
 * - 结合单价计算“等效成本”
 * - 根据任务语言类型推荐最省 Token / 最省钱的模型
 *
 * @package Kode\AiAgent\Token
 *
 * @example
 * ```php
 * $balancer = new TokenBalancer();
 *
 * // 比较两个模型在中文文本下的等效 Token
 * $a = $balancer->equivalentTokens('claude-3-5-sonnet', 1000, '你好世界');
 * $b = $balancer->equivalentTokens('deepseek-chat', 1000, '你好世界');
 *
 * // 推荐模型
 * $best = $balancer->recommend(['claude-3-5-sonnet', 'deepseek-chat'], $prompt);
 * ```
 */
final readonly class TokenBalancer
{
    private ModelTokenEfficiency $efficiency;
    private TokenCounter $counter;

    public function __construct(
        ?ModelTokenEfficiency $efficiency = null,
        ?TokenCounter $counter = null,
    ) {
        $this->efficiency = $efficiency ?? new ModelTokenEfficiency();
        $this->counter = $counter ?? new TokenCounter();
    }

    /**
     * 将某模型下的 Token 数换算为基准等效 Token 数
     *
     * @param string $model 模型名称
     * @param int $tokens 该模型报告的 Token 数
     * @param string $text 原始文本（用于检测语言场景）
     */
    public function equivalentTokens(string $model, int $tokens, string $text): int
    {
        $language = $this->efficiency->detectLanguage($text);
        return $this->efficiency->normalize($model, $tokens, $language);
    }

    /**
     * 估算某模型处理指定文本所需的基准等效 Token 数
     *
     * @param string $model 模型名称
     * @param string $text 文本
     */
    public function estimateEquivalentTokens(string $model, string $text): int
    {
        $language = $this->efficiency->detectLanguage($text);
        $tokens = $this->counter->estimate($text);
        return $this->efficiency->denormalize($model, $tokens, $language);
    }

    /**
     * 结合 Token 效率和价格，计算“等效成本指数”
     *
     * 指数越低越便宜。用于在路由时平衡 Token 消耗与成本。
     *
     * @param string $model 模型名称
     * @param float $promptPrice 每 1k prompt tokens 价格（美元）
     * @param float $completionPrice 每 1k completion tokens 价格（美元）
     * @param string $text 文本
     */
    public function costIndex(string $model, float $promptPrice, float $completionPrice, string $text): float
    {
        $language = $this->efficiency->detectLanguage($text);
        $index = $this->efficiency->index($model, $language);

        // 等效成本 = 价格 × 效率指数
        return round(($promptPrice + $completionPrice) * $index, 8);
    }

    /**
     * 从候选模型中推荐最省 Token 的模型
     *
     * @param array<int, string> $models 候选模型列表
     * @param string $text 文本
     * @return string 推荐模型
     */
    public function recommendMostEfficient(array $models, string $text): string
    {
        if ($models === []) {
            throw new \InvalidArgumentException('候选模型列表不能为空');
        }

        $best = null;
        $bestTokens = PHP_INT_MAX;

        foreach ($models as $model) {
            $tokens = $this->estimateEquivalentTokens($model, $text);
            if ($tokens < $bestTokens) {
                $bestTokens = $tokens;
                $best = $model;
            }
        }

        return $best ?? $models[0];
    }

    /**
     * 从候选模型中推荐最便宜的模型
     *
     * @param array<int, string> $models 候选模型列表
     * @param array<string, array{prompt: float, completion: float}> $prices 模型价格表
     * @param string $text 文本
     */
    public function recommendCheapest(
        array $models,
        array $prices,
        string $text,
    ): string {
        if ($models === []) {
            throw new \InvalidArgumentException('候选模型列表不能为空');
        }

        $best = null;
        $bestIndex = PHP_FLOAT_MAX;

        foreach ($models as $model) {
            $price = $prices[$model] ?? ['prompt' => 0.001, 'completion' => 0.002];
            $index = $this->costIndex($model, $price['prompt'], $price['completion'], $text);
            if ($index < $bestIndex) {
                $bestIndex = $index;
                $best = $model;
            }
        }

        return $best ?? $models[0];
    }

    /**
     * 生成多模型对比报告
     *
     * @param array<int, string> $models 候选模型
     * @param string $text 文本
     * @param array<string, array{prompt: float, completion: float}> $prices 价格表
     * @return array<int, array{model: string, estimated_tokens: int, equivalent_tokens: int, cost_index: float}>
     */
    public function report(array $models, string $text, array $prices = []): array
    {
        $result = [];
        $language = $this->efficiency->detectLanguage($text);
        $estimated = $this->counter->estimate($text);

        foreach ($models as $model) {
            $equivalent = $this->efficiency->denormalize($model, $estimated, $language);
            $price = $prices[$model] ?? ['prompt' => 0.001, 'completion' => 0.002];
            $costIndex = $this->costIndex($model, $price['prompt'], $price['completion'], $text);

            $result[] = [
                'model' => $model,
                'estimated_tokens' => $estimated,
                'equivalent_tokens' => $equivalent,
                'cost_index' => $costIndex,
            ];
        }

        usort($result, static fn($a, $b) => $a['equivalent_tokens'] <=> $b['equivalent_tokens']);
        return $result;
    }
}
