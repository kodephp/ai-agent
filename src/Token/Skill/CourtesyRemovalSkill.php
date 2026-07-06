<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token\Skill;

/**
 * 客套话去除技能
 *
 * 去除末尾礼貌用语，这些用语对 AI 理解任务帮助有限，却消耗 Token。
 *
 * @package Kode\AiAgent\Token\Skill
 */
final readonly class CourtesyRemovalSkill implements CompressionSkillInterface
{
    private const SUFFIXES = [
        '谢谢',
        '非常感谢',
        '麻烦了',
        '辛苦你了',
        '辛苦您了',
        '拜托了',
        '多谢',
        '感谢',
        '祝好',
        '此致敬礼',
    ];

    public function name(): string
    {
        return 'courtesy_removal';
    }

    public function applicable(array $context = []): bool
    {
        return true;
    }

    public function compress(string $text, array $context = []): string
    {
        foreach (self::SUFFIXES as $suffix) {
            $pattern = '/[，,。.\s]*' . preg_quote($suffix, '/') . '[!！。.，,]?\s*$/u';
            $text = preg_replace($pattern, '', $text) ?? $text;
        }
        return trim($text);
    }
}
