<?php

declare(strict_types=1);

namespace Kode\AiAgent\Attribute;

/**
 * 工具注解
 * 
 * 标记方法为 AI 可调用的工具。
 * 
 * @package Kode\AiAgent\Attribute
 * 
 * @example
 * ```php
 * class MyTools
 * {
 *     #[Tool(name: 'calculator', description: '执行数学计算')]
 *     public function calculate(int $a, int $b): int
 *     {
 *         return $a + $b;
 *     }
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
readonly class Tool
{
    /**
     * @param string $name 工具名称
     * @param string $description 工具描述
     * @param array $parameters 参数定义
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters = [],
    ) {}
}
