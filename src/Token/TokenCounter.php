<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token;

/**
 * Token 计数器
 *
 * 提供多精度的 Token 估算：
 * - 快速估算：基于字符数和中英文字符比例
 * - 字节级估算：考虑标点和特殊字符
 *
 * @package Kode\AiAgent\Token
 */
final class TokenCounter
{
    /**
     * 估算文本的 Token 数
     *
     * 算法说明（基于 GPT 系列 BPE 经验值）：
     * - 中文字符：约 1.5 字符 = 1 token
     * - 英文单词：约 4 字符 = 1 token
     * - 数字：约 3 字符 = 1 token
     * - 标点符号：约 1 个 = 1 token
     */
    public function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        // 中文字符数
        $chineseCount = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text);
        // 英文单词数（连续英文字母）
        $englishWords = preg_match_all('/[a-zA-Z]+/u', $text);
        // 数字序列数
        $numbers = preg_match_all('/\d+/', $text);
        // 标点符号数
        $punctuations = preg_match_all('/[.,!?;:。，！？；：、]/u', $text);
        // 剩余字符（空白、特殊符号等）
        $totalLength = mb_strlen($text, 'UTF-8');
        $countedLength = $chineseCount + ($englishWords * 4) + ($numbers * 3) + $punctuations;
        $otherChars = max(0, $totalLength - $countedLength);

        $chineseTokens = (int) ceil($chineseCount / 1.5);
        $englishTokens = (int) ceil($englishWords * 1.3);
        $numberTokens = (int) ceil($numbers / 2);
        $punctuationTokens = (int) ceil($punctuations * 0.5);
        $otherTokens = (int) ceil($otherChars / 4);

        return max(1, $chineseTokens + $englishTokens + $numberTokens + $punctuationTokens + $otherTokens);
    }

    /**
     * 批量估算多段文本的总 Token 数
     *
     * @param array<int, string> $texts
     */
    public function batch(array $texts): int
    {
        $total = 0;
        foreach ($texts as $text) {
            $total += $this->estimate($text);
        }
        return $total;
    }

    /**
     * 估算消息列表的 Token 数（OpenAI 格式）
     *
     * 每条消息额外增加 ~4 tokens（role、name 等元数据开销）
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function messages(array $messages): int
    {
        $total = 0;
        foreach ($messages as $message) {
            $content = is_string($message['content'] ?? null) ? $message['content'] : '';
            $total += $this->estimate($content) + 4;
        }
        return $total;
    }
}
