<?php

declare(strict_types=1);

namespace Kode\AiAgent\Attribute;

/**
 * 速率限制注解
 * 
 * 设置方法调用频率限制。
 * 
 * @package Kode\AiAgent\Attribute
 * 
 * @example
 * ```php
 * #[RateLimit(requests: 60, per: 'minute')]
 * public function chat(string $message): Response
 * {
 *     // ...
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
readonly class RateLimit
{
    /**
     * @param int $requests 请求数量
     * @param string $per 时间单位 (second, minute, hour, day)
     * @param string|null $key 限制键模板
     */
    public function __construct(
        public int $requests = 60,
        public string $per = 'minute',
        public ?string $key = null,
    ) {}
}
