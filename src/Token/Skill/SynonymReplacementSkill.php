<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token\Skill;

/**
 * 同义词替换压缩技能
 *
 * 将冗长中文表达替换为更短的等价词，降低中文 Token 密度。
 *
 * @package Kode\AiAgent\Token\Skill
 */
final readonly class SynonymReplacementSkill implements CompressionSkillInterface
{
    /**
     * 同义词映射表：长表达 => 短表达
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
        '在此基础上' => '基于此',
        '综上所述' => '综上',
        '总的来说' => '总之',
        '总而言之' => '总之',
        '非常感谢' => '谢谢',
        '万分感谢' => '谢谢',
        '请详细' => '详',
        '请简要' => '简',
        '请尽量' => '尽',
    ];

    public function name(): string
    {
        return 'synonym_replacement';
    }

    public function applicable(array $context = []): bool
    {
        $text = $context['text'] ?? '';
        foreach (array_keys(self::SYNONYMS) as $key) {
            if (str_contains($text, $key)) {
                return true;
            }
        }
        return false;
    }

    public function compress(string $text, array $context = []): string
    {
        return str_replace(
            array_keys(self::SYNONYMS),
            array_values(self::SYNONYMS),
            $text
        );
    }
}
