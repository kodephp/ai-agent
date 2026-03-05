<?php

declare(strict_types=1);

namespace Kode\AiAgent\Attribute;

/**
 * 重试注解
 * 
 * 配置方法重试策略。
 * 
 * @package Kode\AiAgent\Attribute
 * 
 * @example
 * ```php
 * #[Retry(maxAttempts: 3, delay: 1000, multiplier: 2)]
 * public function send(PromptInterface $prompt): ResponseInterface
 * {
 *     // ...
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Retry
{
    /**
     * @param int $maxAttempts 最大重试次数
     * @param int $delay 初始延迟(毫秒)
     * @param int $multiplier 延迟倍数
     * @param int $maxDelay 最大延迟(毫秒)
     * @param array<int> $retryOn 重试的状态码列表
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $delay = 1000,
        public int $multiplier = 2,
        public int $maxDelay = 10000,
        public array $retryOn = [429, 500, 502, 503, 504],
    ) {}
}
