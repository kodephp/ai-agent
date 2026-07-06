<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token\Skill;

/**
 * Markdown 装饰精简技能
 *
 * 去除标题标记、加粗、斜体、引用等装饰性符号，保留语义。
 *
 * @package Kode\AiAgent\Token\Skill
 */
final readonly class MarkdownStripSkill implements CompressionSkillInterface
{
    public function name(): string
    {
        return 'markdown_strip';
    }

    public function applicable(array $context = []): bool
    {
        return str_contains($context['text'] ?? '', '#')
            || str_contains($context['text'] ?? '', '*')
            || str_contains($context['text'] ?? '', '_')
            || str_contains($context['text'] ?? '', '>');
    }

    public function compress(string $text, array $context = []): string
    {
        // 标题层级降级或去除
        $text = preg_replace('/^#{1,2}\s+/um', '', $text) ?? $text;
        $text = preg_replace('/^#{3,6}\s+/um', '### ', $text) ?? $text;

        // 加粗、斜体
        $text = preg_replace('/\*\*(.+?)\*\*/u', '$1', $text) ?? $text;
        $text = preg_replace('/\*(.+?)\*/u', '$1', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/u', '$1', $text) ?? $text;
        $text = preg_replace('/_(.+?)_/u', '$1', $text) ?? $text;

        // 引用
        $text = preg_replace('/^>\s*/um', '', $text) ?? $text;

        // 代码块标记保留内容
        $text = preg_replace('/^```[a-z]*\n/um', '', $text) ?? $text;
        $text = preg_replace('/\n```\s*$/um', '', $text) ?? $text;

        return trim($text);
    }
}
