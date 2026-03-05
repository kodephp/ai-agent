<?php

declare(strict_types=1);

namespace Kode\AiAgent\Pipeline;

use Closure;

/**
 * 管道处理器
 * 
 * 实现中间件管道模式，支持 PHP 8.5+ 管道操作符。
 * 
 * @package Kode\AiAgent\Pipeline
 * 
 * @example
 * ```php
 * $pipeline = new Pipeline();
 * 
 * $pipeline->pipe(function ($input, $next) {
 *     echo "处理前\n";
 *     $result = $next($input);
 *     echo "处理后\n";
 *     return $result;
 * });
 * 
 * $result = $pipeline->process('hello');
 * ```
 */
final class Pipeline
{
    private array $stages = [];

    /**
     * 添加处理阶段
     */
    public function pipe(callable $stage): static
    {
        $this->stages[] = $stage;
        return $this;
    }

    /**
     * 执行管道处理
     */
    public function process(mixed $input, ?callable $destination = null): mixed
    {
        $stages = array_reverse($this->stages);

        $pipeline = array_reduce(
            $stages,
            $this->carry(...),
            $this->prepareDestination($destination)
        );

        return $pipeline($input);
    }

    /**
     * 重置管道
     */
    public function reset(): static
    {
        $this->stages = [];
        return $this;
    }

    /**
     * 获取阶段数量
     */
    public function count(): int
    {
        return count($this->stages);
    }

    /**
     * 检查是否为空
     */
    public function isEmpty(): bool
    {
        return empty($this->stages);
    }

    /**
     * 准备目标处理器
     */
    private function prepareDestination(?callable $destination): Closure
    {
        if ($destination === null) {
            return static fn(mixed $input): mixed => $input;
        }

        return static fn(mixed $input): mixed => $destination($input);
    }

    /**
     * 包装阶段
     * 
     * array_reduce 回调: $carry 是上一次的结果（下一个阶段），$stage 是当前阶段
     */
    private function carry(callable $carry, callable $stage): Closure
    {
        return static fn(mixed $input): mixed => $stage($input, $carry);
    }
}
