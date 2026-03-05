<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Model;

use Kode\Context\Context as KodeContext;

/**
 * 上下文值对象
 * 
 * 封装 kode/context 包，用于请求级数据传递，支持协程隔离。
 * 
 * @package Kode\AiAgent\Domain\Model
 * 
 * @example
 * ```php
 * $context = Context::create(['user_id' => 123]);
 * $context->set('request_id', 'req-xxx');
 * 
 * // 在协程环境中自动隔离
 * $value = $context->get('user_id');
 * ```
 */
final class Context
{
    private const NAMESPACE = 'ai_agent';

    /**
     * 创建新上下文
     */
    public static function create(array $data = []): self
    {
        $instance = new self();
        foreach ($data as $key => $value) {
            $instance->set($key, $value);
        }
        return $instance;
    }

    /**
     * 设置数据
     */
    public function set(string $key, mixed $value): void
    {
        KodeContext::set(self::prefix($key), $value);
    }

    /**
     * 获取数据
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return KodeContext::get(self::prefix($key), $default);
    }

    /**
     * 检查键是否存在
     */
    public function has(string $key): bool
    {
        return KodeContext::has(self::prefix($key));
    }

    /**
     * 删除键
     */
    public function delete(string $key): void
    {
        KodeContext::delete(self::prefix($key));
    }

    /**
     * 获取所有数据
     */
    public function all(): array
    {
        $keys = KodeContext::keys();
        $data = [];
        $prefix = self::NAMESPACE . '.';
        
        foreach ($keys as $key) {
            if (str_starts_with($key, $prefix)) {
                $data[substr($key, strlen($prefix))] = KodeContext::get($key);
            }
        }
        
        return $data;
    }

    /**
     * 获取请求ID
     */
    public function requestId(): string
    {
        return $this->get('request_id', '');
    }

    /**
     * 设置请求ID
     */
    public function withRequestId(string $requestId): self
    {
        $this->set('request_id', $requestId);
        return $this;
    }

    /**
     * 清空当前命名空间下的所有数据
     */
    public function clear(): void
    {
        $keys = KodeContext::keys();
        $prefix = self::NAMESPACE . '.';
        
        foreach ($keys as $key) {
            if (str_starts_with($key, $prefix)) {
                KodeContext::delete($key);
            }
        }
    }

    /**
     * 在新的上下文作用域中执行回调
     */
    public static function run(callable $callable): mixed
    {
        return KodeContext::run($callable);
    }

    /**
     * 添加前缀
     */
    private static function prefix(string $key): string
    {
        return self::NAMESPACE . '.' . $key;
    }
}
