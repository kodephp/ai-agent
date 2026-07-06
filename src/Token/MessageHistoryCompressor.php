<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token;

/**
 * 消息历史压缩器
 *
 * 对对话历史进行智能裁剪和摘要，在 Token 预算内保留最有价值的信息。
 *
 * 策略：
 * - 保留系统消息
 * - 保留最近 N 轮对话
 * - 对中间历史进行摘要（可选）
 * - 滑动窗口
 *
 * @package Kode\AiAgent\Token
 */
final class MessageHistoryCompressor
{
    public function __construct(
        private readonly TokenCounter $counter = new TokenCounter(),
    ) {}

    /**
     * 按 Token 预算裁剪消息列表
     *
     * @param array<int, array{role: string, content: string, name?: string}> $messages
     * @return array<int, array{role: string, content: string, name?: string}>
     */
    public function compress(array $messages, int $maxTokens, bool $keepSystem = true): array
    {
        if ($messages === [] || $maxTokens <= 0) {
            return $keepSystem ? array_values(array_filter(
                $messages,
                static fn($m) => $m['role'] === 'system'
            )) : [];
        }

        $systemMessages = [];
        $otherMessages = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system' && $keepSystem) {
                $systemMessages[] = $message;
            } else {
                $otherMessages[] = $message;
            }
        }

        $systemTokens = $this->counter->messages($systemMessages);
        $remainingTokens = max(0, $maxTokens - $systemTokens);

        if ($remainingTokens <= 0) {
            return $systemMessages;
        }

        // 从最新消息开始向前累积，直到达到预算
        $selected = [];
        $usedTokens = 0;
        $candidates = array_reverse($otherMessages);

        foreach ($candidates as $message) {
            $msgTokens = $this->counter->estimate((string) $message['content']) + 4;
            if ($usedTokens + $msgTokens > $remainingTokens) {
                break;
            }
            $selected[] = $message;
            $usedTokens += $msgTokens;
        }

        $selected = array_reverse($selected);

        return array_merge($systemMessages, $selected);
    }

    /**
     * 滑动窗口：保留最近 N 条非系统消息
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array{role: string, content: string}>
     */
    public function slidingWindow(array $messages, int $windowSize, bool $keepSystem = true): array
    {
        $systemMessages = [];
        $otherMessages = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system' && $keepSystem) {
                $systemMessages[] = $message;
            } else {
                $otherMessages[] = $message;
            }
        }

        if (count($otherMessages) <= $windowSize) {
            return $messages;
        }

        $tail = array_slice($otherMessages, -$windowSize);
        return array_merge($systemMessages, $tail);
    }

    /**
     * 计算压缩节省量
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{original: int, compressed: int, saved: int, ratio: float}
     */
    public function savings(array $messages, int $maxTokens, bool $keepSystem = true): array
    {
        $originalTokens = $this->counter->messages($messages);
        $compressed = $this->compress($messages, $maxTokens, $keepSystem);
        $compressedTokens = $this->counter->messages($compressed);

        return [
            'original' => $originalTokens,
            'compressed' => $compressedTokens,
            'saved' => max(0, $originalTokens - $compressedTokens),
            'ratio' => $originalTokens > 0
                ? round(($originalTokens - $compressedTokens) / $originalTokens, 4)
                : 0.0,
        ];
    }
}
