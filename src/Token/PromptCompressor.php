<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token;

/**
 * Prompt 压缩器
 *
 * 通过多种策略降低 Prompt 的 Token 消耗，提升性价比和响应速度。
 *
 * 压缩策略：
 * 1. 空白规范化：合并多余空白字符
 * 2. 同义词替换：将冗长词替换为简短同义词
 * 3. 冗余内容去除：去除礼貌用语、客套话
 * 4. Markdown 精简：移除装饰性符号
 * 5. 上下文裁剪：基于 Token 预算自动裁剪历史
 *
 * @package Kode\AiAgent\Token
 *
 * @example
 * ```php
 * $compressor = new PromptCompressor();
 * $compressed = $compressor->compress($longPrompt, maxTokens: 2000);
 * ```
 */
final class PromptCompressor
{
    /**
     * 同义词映射表
     * key 是原词，value 是更短的等价词
     */
    private const SYNONYMS = [
        '请帮我' => '请',
        '请您' => '请',
        '麻烦您' => '请',
        '麻烦' => '请',
        '请您务必' => '请',
        '请您一定' => '请',
        '非常重要' => '重要',
        '特别重要' => '重要',
        '一般情况下' => '通常',
        '一般情况下来说' => '通常',
        '比如说' => '如',
        '举一个例子' => '例',
        '请你帮我' => '请',
        '您可以' => '可',
        '你可以' => '可',
        '能不能' => '能否',
        '可不可以' => '能否',
        '不好意思' => '',
        '非常感谢' => '谢谢',
        '万分感谢' => '谢谢',
        '在此基础上' => '基于此',
        '综上所述' => '综上',
        '总的来说' => '总之',
        '总而言之' => '总之',
    ];

    /**
     * 礼貌客套后缀
     */
    private const COURTESY_SUFFIXES = [
        '谢谢',
        '非常感谢',
        '麻烦了',
        '辛苦你了',
        '辛苦您了',
    ];

    public function __construct(
        private readonly TokenCounter $counter = new TokenCounter(),
    ) {}

    /**
     * 压缩 Prompt
     *
     * @param string $prompt 原始 Prompt
     * @param int|null $maxTokens 目标最大 Token 数（超过则继续压缩）
     * @return string 压缩后的 Prompt
     */
    public function compress(string $prompt, ?int $maxTokens = null): string
    {
        $result = $prompt;

        // 1. 空白规范化
        $result = $this->normalizeWhitespace($result);

        // 2. Markdown 精简
        $result = $this->stripMarkdownDecoration($result);

        // 3. 同义词替换
        $result = $this->applySynonyms($result);

        // 4. 去除客套
        $result = $this->stripCourtesy($result);

        // 5. 如果仍然超限，按 Token 预算裁剪
        if ($maxTokens !== null) {
            $result = $this->truncateByTokens($result, $maxTokens);
        }

        return $result;
    }

    /**
     * 获取压缩前后的 Token 节省量
     *
     * @return array{original: int, compressed: int, saved: int, ratio: float}
     */
    public function savings(string $original, ?int $maxTokens = null): array
    {
        $originalTokens = $this->counter->estimate($original);
        $compressed = $this->compress($original, $maxTokens);
        $compressedTokens = $this->counter->estimate($compressed);

        return [
            'original' => $originalTokens,
            'compressed' => $compressedTokens,
            'saved' => max(0, $originalTokens - $compressedTokens),
            'ratio' => $originalTokens > 0
                ? round(($originalTokens - $compressedTokens) / $originalTokens, 4)
                : 0.0,
        ];
    }

    /**
     * 规范化空白
     */
    private function normalizeWhitespace(string $text): string
    {
        // 合并多个空格
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        // 合并多个换行
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        // 去除行尾空白
        $text = preg_replace('/[ \t]+$/um', '', $text);
        return trim($text);
    }

    /**
     * 精简 Markdown 装饰
     */
    private function stripMarkdownDecoration(string $text): string
    {
        // 标题层级降级
        $text = preg_replace('/^#{1,2}\s+/um', '', $text);
        $text = preg_replace('/^#{3,6}\s+/um', '### ', $text);
        // 加粗、斜体符号
        $text = preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);
        $text = preg_replace('/\*(.+?)\*/u', '$1', $text);
        $text = preg_replace('/__(.+?)__/u', '$1', $text);
        $text = preg_replace('/_(.+?)_/u', '$1', $text);
        // 引用符号
        $text = preg_replace('/^>\s*/um', '', $text);
        return $text;
    }

    /**
     * 应用同义词替换
     */
    private function applySynonyms(string $text): string
    {
        return str_replace(
            array_keys(self::SYNONYMS),
            array_values(self::SYNONYMS),
            $text
        );
    }

    /**
     * 去除客套后缀
     */
    private function stripCourtesy(string $text): string
    {
        foreach (self::COURTESY_SUFFIXES as $suffix) {
            $text = preg_replace('/[，,。.\s]*' . preg_quote($suffix, '/') . '[!！。.，,]?\s*$/u', '', $text);
        }
        return trim($text);
    }

    /**
     * 按 Token 数裁剪（保留完整句子）
     */
    private function truncateByTokens(string $text, int $maxTokens): string
    {
        if ($this->counter->estimate($text) <= $maxTokens) {
            return $text;
        }

        // 按句子切分
        $sentences = preg_split('/(?<=[。！？.!?\n])/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        $kept = '';
        foreach ($sentences as $sentence) {
            $candidate = $kept . $sentence;
            if ($this->counter->estimate($candidate) > $maxTokens) {
                break;
            }
            $kept = $candidate;
        }

        return $kept;
    }
}
