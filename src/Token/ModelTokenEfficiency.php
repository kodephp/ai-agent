<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token;

/**
 * 模型 Token 效率指数
 *
 * 不同模型对同一文本的 Token 化结果差异很大：
 * - Claude 系列对中文切分较细，中文场景下 Token 数通常比 GPT-4o 多 20%~40%
 * - Gemini Flash 对长上下文压缩较好
 * - DeepSeek、Qwen 对中文优化较好
 *
 * 本值对象提供一个“效率指数”，用于在跨模型路由时把 Token 消耗归一化，
 * 从而真正比较“同样语义下哪个模型更省 Token”。
 *
 * @package Kode\AiAgent\Token
 */
final readonly class ModelTokenEfficiency
{
    /**
     * 模型效率表
     *
     * 基准为 1.0：指数越高，表示相同文本在该模型下 Token 越多（效率越低）。
     * 数据基于公开评测和官方 Tokenizer 经验值。
     *
     * @var array<string, array{overall: float, chinese: float, english: float, code: float}>
     */
    private const INDEX = [
        // OpenAI
        'gpt-4o' => ['overall' => 1.0, 'chinese' => 1.0, 'english' => 1.0, 'code' => 1.0],
        'gpt-4o-mini' => ['overall' => 1.05, 'chinese' => 1.05, 'english' => 1.05, 'code' => 1.05],
        'gpt-4-turbo' => ['overall' => 1.0, 'chinese' => 1.0, 'english' => 1.0, 'code' => 1.0],
        'gpt-3.5-turbo' => ['overall' => 1.1, 'chinese' => 1.15, 'english' => 1.05, 'code' => 1.1],
        'o1-preview' => ['overall' => 1.0, 'chinese' => 1.0, 'english' => 1.0, 'code' => 1.0],
        'o1-mini' => ['overall' => 1.05, 'chinese' => 1.05, 'english' => 1.05, 'code' => 1.05],

        // Anthropic：中文 Token 化较细
        'claude-3-5-sonnet' => ['overall' => 1.15, 'chinese' => 1.35, 'english' => 1.0, 'code' => 1.05],
        'claude-3-5-haiku' => ['overall' => 1.15, 'chinese' => 1.35, 'english' => 1.0, 'code' => 1.05],
        'claude-3-opus' => ['overall' => 1.15, 'chinese' => 1.35, 'english' => 1.0, 'code' => 1.05],
        'claude-3-haiku' => ['overall' => 1.15, 'chinese' => 1.35, 'english' => 1.0, 'code' => 1.05],

        // DeepSeek：中文优化好
        'deepseek-chat' => ['overall' => 0.9, 'chinese' => 0.85, 'english' => 0.95, 'code' => 0.9],
        'deepseek-reasoner' => ['overall' => 0.95, 'chinese' => 0.9, 'english' => 0.95, 'code' => 0.9],

        // 阿里云
        'qwen-turbo' => ['overall' => 0.95, 'chinese' => 0.9, 'english' => 1.0, 'code' => 0.95],
        'qwen-plus' => ['overall' => 0.95, 'chinese' => 0.9, 'english' => 1.0, 'code' => 0.95],
        'qwen-max' => ['overall' => 0.95, 'chinese' => 0.9, 'english' => 1.0, 'code' => 0.95],
        'qwen-long' => ['overall' => 0.95, 'chinese' => 0.9, 'english' => 1.0, 'code' => 0.95],

        // 百度
        'ernie-4.0' => ['overall' => 1.0, 'chinese' => 0.95, 'english' => 1.05, 'code' => 1.0],
        'ernie-3.5' => ['overall' => 1.0, 'chinese' => 0.95, 'english' => 1.05, 'code' => 1.0],
        'ernie-speed' => ['overall' => 1.0, 'chinese' => 0.95, 'english' => 1.05, 'code' => 1.0],

        // 腾讯
        'hunyuan-pro' => ['overall' => 1.05, 'chinese' => 1.0, 'english' => 1.05, 'code' => 1.05],
        'hunyuan-standard' => ['overall' => 1.05, 'chinese' => 1.0, 'english' => 1.05, 'code' => 1.05],

        // 讯飞
        'spark-v4.0' => ['overall' => 1.1, 'chinese' => 1.05, 'english' => 1.1, 'code' => 1.1],
        'spark-v3.5' => ['overall' => 1.1, 'chinese' => 1.05, 'english' => 1.1, 'code' => 1.1],

        // Gemini
        'gemini-1.5-pro' => ['overall' => 1.05, 'chinese' => 1.1, 'english' => 1.0, 'code' => 1.0],
        'gemini-1.5-flash' => ['overall' => 1.0, 'chinese' => 1.05, 'english' => 0.95, 'code' => 0.95],
        'gemini-2.0-flash' => ['overall' => 1.0, 'chinese' => 1.05, 'english' => 0.95, 'code' => 0.95],
    ];

    /**
     * 获取模型在指定语言场景下的效率指数
     *
     * @param string $model 模型名称
     * @param string $language 语言场景：chinese|english|code|overall
     */
    public function index(string $model, string $language = 'overall'): float
    {
        $language = in_array($language, ['chinese', 'english', 'code', 'overall'], true)
            ? $language
            : 'overall';

        return self::INDEX[$model][$language]
            ?? self::INDEX[$model]['overall']
            ?? 1.0;
    }

    /**
     * 将某模型下的 Token 数归一化为基准 Token 数
     *
     * 例如：Claude 中文指数 1.35，则 135 tokens 相当于 gpt-4o 的 100 tokens。
     *
     * @param string $model 模型名称
     * @param int $tokens 该模型下估算的 Token 数
     * @param string $language 语言场景
     */
    public function normalize(string $model, int $tokens, string $language = 'overall'): int
    {
        return (int) round($tokens / $this->index($model, $language));
    }

    /**
     * 将基准 Token 数转换为某模型下的 Token 数
     *
     * @param string $model 目标模型
     * @param int $baseTokens 基准 Token 数
     * @param string $language 语言场景
     */
    public function denormalize(string $model, int $baseTokens, string $language = 'overall'): int
    {
        return (int) round($baseTokens * $this->index($model, $language));
    }

    /**
     * 检测文本主导语言场景
     */
    public function detectLanguage(string $text): string
    {
        $chineseCount = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text);
        $englishWords = preg_match_all('/[a-zA-Z]+/u', $text);
        $codeMarks = preg_match_all('/[{};<>=()+\[\]|&!]/u', $text);

        $total = mb_strlen($text, 'UTF-8');
        if ($total === 0) {
            return 'overall';
        }

        if ($codeMarks / $total > 0.05) {
            return 'code';
        }

        if ($chineseCount / $total > 0.3) {
            return 'chinese';
        }

        if ($englishWords / $total > 0.15) {
            return 'english';
        }

        return 'overall';
    }

    /**
     * 比较两个模型在同样文本下的相对 Token 消耗
     *
     * @return float 正值表示 modelA 更费 Token，负值表示 modelB 更费 Token
     */
    public function compare(string $modelA, string $modelB, string $text): float
    {
        $language = $this->detectLanguage($text);
        return $this->index($modelA, $language) - $this->index($modelB, $language);
    }
}
