<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token;

use Kode\AiAgent\Token\Skill\CompressionSkillInterface;

/**
 * 基于技能的 Prompt 压缩器
 *
 * 将多个压缩技能按优先级组合，自动选择适用的技能进行链式压缩。
 * 支持 Token 预算裁剪，并提供压缩前后的节省统计。
 *
 * @package Kode\AiAgent\Token
 *
 * @example
 * ```php
 * $compressor = new SkillBasedCompressor([
 *     new Skill\WhitespaceNormalizeSkill(),
 *     new Skill\MarkdownStripSkill(),
 *     new Skill\SynonymReplacementSkill(),
 *     new Skill\CourtesyRemovalSkill(),
 * ]);
 *
 * $compressed = $compressor->compress($longPrompt, maxTokens: 2000);
 * $savings = $compressor->savings($longPrompt, maxTokens: 2000);
 * ```
 */
final readonly class SkillBasedCompressor
{
    /**
     * 默认技能链：安全、无损、语义保留
     */
    private const DEFAULT_SKILLS = [
        Skill\WhitespaceNormalizeSkill::class,
        Skill\MarkdownStripSkill::class,
        Skill\SynonymReplacementSkill::class,
        Skill\CourtesyRemovalSkill::class,
    ];

    /** @var array<int, CompressionSkillInterface> */
    private array $skills;

    private TokenCounter $counter;

    /**
     * @param array<int, CompressionSkillInterface>|null $skills 自定义技能链
     */
    public function __construct(?array $skills = null)
    {
        $this->skills = $skills ?? $this->defaultSkills();
        $this->counter = new TokenCounter();
    }

    /**
     * 压缩文本
     *
     * @param string $text 原始文本
     * @param int|null $maxTokens 目标最大 Token 数
     * @param array<string, mixed> $context 上下文
     */
    public function compress(string $text, ?int $maxTokens = null, array $context = []): string
    {
        $context['text'] = $text;
        $result = $text;

        foreach ($this->skills as $skill) {
            if ($skill->applicable($context)) {
                $result = $skill->compress($result, $context);
            }
        }

        if ($maxTokens !== null) {
            $result = $this->truncateByTokens($result, $maxTokens);
        }

        return $result;
    }

    /**
     * 计算压缩节省量
     *
     * @return array{original: int, compressed: int, saved: int, ratio: float, skills: array<int, string>}
     */
    public function savings(string $original, ?int $maxTokens = null, array $context = []): array
    {
        $originalTokens = $this->counter->estimate($original);
        $compressed = $this->compress($original, $maxTokens, $context);
        $compressedTokens = $this->counter->estimate($compressed);

        return [
            'original' => $originalTokens,
            'compressed' => $compressedTokens,
            'saved' => max(0, $originalTokens - $compressedTokens),
            'ratio' => $originalTokens > 0
                ? round(($originalTokens - $compressedTokens) / $originalTokens, 4)
                : 0.0,
            'skills' => array_map(
                static fn(CompressionSkillInterface $s) => $s->name(),
                $this->skills
            ),
        ];
    }

    /**
     * 获取当前技能链
     *
     * @return array<int, CompressionSkillInterface>
     */
    public function skills(): array
    {
        return $this->skills;
    }

    /**
     * 按 Token 数裁剪，保留完整句子
     */
    private function truncateByTokens(string $text, int $maxTokens): string
    {
        if ($this->counter->estimate($text) <= $maxTokens) {
            return $text;
        }

        $sentences = preg_split('/(?<=[。！？.!?\n])/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
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

    /**
     * 实例化默认技能链
     *
     * @return array<int, CompressionSkillInterface>
     */
    private function defaultSkills(): array
    {
        return array_map(
            static fn(string $class) => new $class(),
            self::DEFAULT_SKILLS
        );
    }
}
