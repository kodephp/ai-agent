<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token\Skill;

/**
 * 空白规范化压缩技能
 *
 * 合并多余空格、换行，去除行尾空白，降低无意义 Token。
 *
 * @package Kode\AiAgent\Token\Skill
 */
final readonly class WhitespaceNormalizeSkill implements CompressionSkillInterface
{
    public function name(): string
    {
        return 'whitespace_normalize';
    }

    public function applicable(array $context = []): bool
    {
        return true;
    }

    public function compress(string $text, array $context = []): string
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+$/um', '', $text) ?? $text;
        return trim($text);
    }
}
