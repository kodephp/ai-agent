<?php

declare(strict_types=1);

namespace Kode\AiAgent\Attribute;

/**
 * 缓存注解
 * 
 * 启用方法响应缓存。
 * 
 * @package Kode\AiAgent\Attribute
 * 
 * @example
 * ```php
 * #[Cache(ttl: 3600, key: 'chat_{hash}')]
 * public function chat(string $message): Response
 * {
 *     // ...
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Cache
{
    /**
     * @param int $ttl 缓存时间(秒)
     * @param string|null $key 缓存键模板
     * @param array<string> $tags 缓存标签
     */
    public function __construct(
        public int $ttl = 3600,
        public ?string $key = null,
        public array $tags = [],
    ) {}
}
