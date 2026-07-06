<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe;

use Kode\AiAgent\Domain\Contract\PromptInterface;
use Kode\AiAgent\Domain\Model\Prompt;
use Kode\AiAgent\Token\SkillBasedCompressor;
use Kode\AiAgent\Token\TokenCounter;

/**
 * 自动压缩中间件
 *
 * 在请求进入专家之前，根据 Token 预算自动压缩 Prompt。
 * 透明、无损语义、可统计节省量，增强用户粘性：
 * 用户无需关心 Token 细节，网关自动帮他们省钱。
 *
 * @package Kode\AiAgent\Moe
 *
 * @example
 * ```php
 * $middleware = new AutoCompressionMiddleware(
 *     threshold: 1000,    // 超过 1000 tokens 触发压缩
 *     targetRatio: 0.7,   // 压缩到原长度的 70%
 * );
 *
 * $compressedPrompt = $middleware->compress($originalPrompt);
 * ```
 */
final class AutoCompressionMiddleware
{
    private SkillBasedCompressor $compressor;
    private TokenCounter $counter;

    /**
     * @param int $threshold 触发压缩的 Token 阈值（0 表示始终压缩）
     * @param float $targetRatio 目标压缩比例（如 0.7 表示保留 70%）
     * @param int $minTokens 最小 Token 数，避免短文本过度压缩
     * @param bool $enabled 是否启用
     */
    public function __construct(
        private readonly int $threshold = 1000,
        private readonly float $targetRatio = 0.75,
        private readonly int $minTokens = 100,
        private readonly bool $enabled = true,
        ?SkillBasedCompressor $compressor = null,
    ) {
        $this->compressor = $compressor ?? new SkillBasedCompressor();
        $this->counter = new TokenCounter();
    }

    /**
     * 压缩 Prompt
     */
    public function compress(PromptInterface $prompt): PromptInterface
    {
        if (!$this->enabled) {
            return $prompt;
        }

        $text = $prompt->text();
        $originalTokens = $this->counter->estimate($text);

        if ($originalTokens < max($this->minTokens, $this->threshold)) {
            return $prompt;
        }

        $targetTokens = (int) max(
            $this->minTokens,
            ceil($originalTokens * $this->targetRatio)
        );

        $compressed = $this->compressor->compress($text, $targetTokens, [
            'language' => 'auto',
        ]);

        return new Prompt($compressed, $prompt->images());
    }

    /**
     * 计算本次压缩可节省的 Token
     *
     * @return array{original: int, compressed: int, saved: int, ratio: float}
     */
    public function savings(PromptInterface $prompt): array
    {
        $text = $prompt->text();
        $originalTokens = $this->counter->estimate($text);

        if ($originalTokens < max($this->minTokens, $this->threshold)) {
            return [
                'original' => $originalTokens,
                'compressed' => $originalTokens,
                'saved' => 0,
                'ratio' => 0.0,
            ];
        }

        $targetTokens = (int) max(
            $this->minTokens,
            ceil($originalTokens * $this->targetRatio)
        );

        return $this->compressor->savings($text, $targetTokens);
    }

    /**
     * 是否启用
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }
}
