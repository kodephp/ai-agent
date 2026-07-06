<?php

declare(strict_types=1);

namespace Kode\AiAgent\Token\Skill;

/**
 * Prompt 压缩技能接口
 *
 * 将不同的压缩策略抽象为可插拔的“技能”，开发者可自由组合、扩展。
 * 技能化设计让压缩逻辑更细粒度、可测试、可复用，也便于后续接入 AI 自动压缩。
 *
 * @package Kode\AiAgent\Token\Skill
 */
interface CompressionSkillInterface
{
    /**
     * 压缩文本
     *
     * @param string $text 原始文本
     * @param array<string, mixed> $context 上下文（如目标模型、预算、语言）
     * @return string 压缩后的文本
     */
    public function compress(string $text, array $context = []): string;

    /**
     * 技能名称
     */
    public function name(): string;

    /**
     * 是否对当前上下文有效
     */
    public function applicable(array $context = []): bool;
}
