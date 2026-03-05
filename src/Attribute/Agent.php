<?php

declare(strict_types=1);

namespace Kode\AiAgent\Attribute;

/**
 * 代理注解
 * 
 * 标记类为 AI 代理类。
 * 
 * @package Kode\AiAgent\Attribute
 * 
 * @example
 * ```php
 * #[Agent(name: 'chat-assistant', description: '聊天助手')]
 * class ChatAgent
 * {
 *     // ...
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Agent
{
    /**
     * @param string $name 代理名称
     * @param string $description 代理描述
     * @param string $systemPrompt 系统提示词
     */
    public function __construct(
        public string $name,
        public string $description = '',
        public string $systemPrompt = '',
    ) {}
}
