<?php

declare(strict_types=1);

namespace Kode\AiAgent\Pipeline;

use Kode\AiAgent\Attribute\{Cache, RateLimit, Retry};
use Kode\AiAgent\Domain\Contract\{PromptInterface, ResponseInterface};
use Kode\Attributes\Reader;
use Psr\SimpleCache\CacheInterface;

/**
 * 注解处理器中间件
 * 
 * 处理 Cache、RateLimit、Retry 注解。
 * 
 * @package Kode\AiAgent\Pipeline
 * 
 * @example
 * ```php
 * $processor = new AnnotationProcessor($cache);
 * $response = $processor->process($prompt, $adapter, $config);
 * ```
 */
final class AnnotationProcessor
{
    private Reader $reader;
    private ?CacheInterface $cache;
    private array $config;
    private array $rateLimitStore = [];

    public function __construct(
        ?CacheInterface $cache = null,
        array $config = []
    ) {
        $this->reader = new Reader();
        $this->cache = $cache;
        $this->config = $config;
    }

    public function config(): array
    {
        return $this->config;
    }

    /**
     * 处理请求
     *
     * @param PromptInterface $prompt 提示词
     * @param object $adapter 适配器实例
     * @param callable $next 下一步处理
     * @return ResponseInterface 响应
     */
    public function process(PromptInterface $prompt, object $adapter, callable $next): ResponseInterface
    {
        $className = get_class($adapter);

        // 获取方法注解
        $cacheAttr = $this->getMethodAttribute($className, 'send', Cache::class);
        $rateLimitAttr = $this->getMethodAttribute($className, 'send', RateLimit::class);
        $retryAttr = $this->getMethodAttribute($className, 'send', Retry::class);

        // 速率限制检查
        if ($rateLimitAttr !== null) {
            $this->checkRateLimit($rateLimitAttr, $className);
        }

        // 缓存检查
        if ($cacheAttr !== null && $this->cache !== null) {
            $cacheKey = $this->buildCacheKey($cacheAttr, $prompt);
            $cached = $this->cache->get($cacheKey);
            
            if ($cached !== null) {
                return $cached;
            }
        }

        // 执行请求（带重试）
        $response = $retryAttr !== null
            ? $this->executeWithRetry($next, $prompt, $retryAttr)
            : $next($prompt);

        // 缓存响应
        if ($cacheAttr !== null && $this->cache !== null) {
            $this->cache->set(
                $this->buildCacheKey($cacheAttr, $prompt),
                $response,
                $cacheAttr->ttl
            );
        }

        return $response;
    }

    /**
     * 获取方法属性
     */
    private function getMethodAttribute(string $className, string $methodName, string $attributeClass): ?object
    {
        try {
            $metaList = $this->reader->getMethodAttrs($className, $methodName);
            
            if ($metaList->has($attributeClass)) {
                $meta = $metaList->get($attributeClass);
                return $meta?->getInstance();
            }
        } catch (\Throwable) {
            // 忽略错误
        }

        return null;
    }

    /**
     * 构建缓存键
     */
    private function buildCacheKey(Cache $attr, PromptInterface $prompt): string
    {
        if ($attr->key !== null) {
            return str_replace('{hash}', md5($prompt->text()), $attr->key);
        }

        return 'ai_agent:' . md5($prompt->text());
    }

    /**
     * 检查速率限制
     */
    private function checkRateLimit(RateLimit $attr, string $className): void
    {
        $key = $this->buildRateLimitKey($attr, $className);
        $now = time();
        
        if (!isset($this->rateLimitStore[$key])) {
            $this->rateLimitStore[$key] = [];
        }

        // 清理过期记录
        $window = $this->getWindowSeconds($attr->per);
        $this->rateLimitStore[$key] = array_filter(
            $this->rateLimitStore[$key],
            fn($timestamp) => $now - $timestamp < $window
        );

        // 检查是否超限
        if (count($this->rateLimitStore[$key]) >= $attr->requests) {
            throw new \RuntimeException("速率限制: 超过 {$attr->requests} 请求/{$attr->per}");
        }

        // 记录请求
        $this->rateLimitStore[$key][] = $now;
    }

    /**
     * 构建速率限制键
     */
    private function buildRateLimitKey(RateLimit $attr, string $className): string
    {
        if ($attr->key !== null) {
            return $attr->key;
        }

        return 'rate_limit:' . $className;
    }

    /**
     * 获取时间窗口（秒）
     */
    private function getWindowSeconds(string $per): int
    {
        return match ($per) {
            'second' => 1,
            'minute' => 60,
            'hour' => 3600,
            'day' => 86400,
            default => 60,
        };
    }

    /**
     * 带重试执行
     */
    private function executeWithRetry(callable $next, PromptInterface $prompt, Retry $attr): ResponseInterface
    {
        $attempts = 0;
        $delay = $attr->delay;
        $lastException = null;

        while ($attempts < $attr->maxAttempts) {
            $attempts++;

            try {
                return $next($prompt);
            } catch (\Throwable $e) {
                $lastException = $e;

                // 检查是否应该重试
                if (!$this->shouldRetry($e, $attr)) {
                    throw $e;
                }

                // 最后一次尝试不等待
                if ($attempts < $attr->maxAttempts) {
                    usleep($delay * 1000);
                    $delay = min($delay * $attr->multiplier, $attr->maxDelay);
                }
            }
        }

        throw $lastException ?? new \RuntimeException('重试失败');
    }

    /**
     * 判断是否应该重试
     */
    private function shouldRetry(\Throwable $e, Retry $attr): bool
    {
        // 如果是特定的异常类型，不重试
        if ($e instanceof \Kode\AiAgent\Exception\AuthenticationException) {
            return false;
        }

        // 检查状态码
        if (method_exists($e, 'getCode')) {
            $code = $e->getCode();
            if (in_array($code, $attr->retryOn, true)) {
                return true;
            }
        }

        // 网络错误重试
        return $e instanceof \Kode\AiAgent\Exception\PlatformException;
    }
}
