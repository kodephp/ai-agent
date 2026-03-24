<?php

declare(strict_types=1);

namespace Kode\AiAgent\Async;

use Kode\AiAgent\Log\LogManager;
use Generator;

/**
 * 并行执行器
 *
 * 提供高级并行任务执行能力，支持批量执行、进度回调和 Map 操作。
 * 自动检测 Fiber 扩展可用性，在不支持 Fiber 的环境下自动降级为顺序执行。
 *
 * @package Kode\AiAgent\Async
 *
 * @example
 * ```php
 * $executor = new ParallelExecutor(concurrency: 4);
 *
 * // 批量执行任务
 * $results = $executor->executeBatch([
 *     fn() => generateImage('场景1'),
 *     fn() => generateImage('场景2'),
 *     fn() => generateImage('场景3'),
 * ], fn($done, $total) => echo "进度: {$done}/{$total}\n");
 *
 * // Map 操作
 * $images = $executor->map($prompts, fn($p) => generateImage($p));
 * ```
 */
final class ParallelExecutor
{
    private FiberPool $pool;
    private int $concurrency;
    private bool $enableParallel;

    /**
     * 创建并行执行器
     *
     * @param int $concurrency 并发数，默认 10
     * @param bool $enableParallel 是否启用并行，false 时降级为顺序执行
     */
    public function __construct(int $concurrency = 10, bool $enableParallel = true)
    {
        $this->concurrency = $concurrency;
        $this->enableParallel = $enableParallel && extension_loaded('Fiber');
        $this->pool = new FiberPool($this->enableParallel ? $concurrency : 1);
    }

    /**
     * 执行单个任务
     *
     * @param callable $task 任务函数
     * @return AsyncTask 异步任务实例
     */
    public function execute(callable $task): AsyncTask
    {
        return $this->pool->submit($task);
    }

    /**
     * 批量执行任务
     *
     * @param array $tasks 任务函数数组
     * @param callable|null $progress 进度回调函数，接受 (已完成数, 总数) 参数
     * @return array 任务结果数组
     */
    public function executeBatch(array $tasks, ?callable $progress = null): array
    {
        if (!$this->enableParallel || $this->concurrency === 1) {
            return $this->executeSequentially($tasks);
        }

        $asyncTasks = $this->pool->submitBatch($tasks);

        if ($progress !== null) {
            return $this->executeWithProgress($asyncTasks, $progress);
        }

        $this->pool->run();

        return array_map(fn($task) => $task->getResult(), $asyncTasks);
    }

    /**
     * 执行生成器任务
     *
     * 用于处理返回 Generator 的任务函数，支持嵌套 Generator。
     *
     * @param callable $task 任务函数
     * @param array $options 配置选项
     * @return Generator
     */
    public function executeGenerator(callable $task, array $options = []): Generator
    {
        $maxConcurrency = $options['concurrency'] ?? $this->concurrency;

        $generator = $task();

        if (!$generator instanceof Generator) {
            throw new \InvalidArgumentException('任务必须返回 Generator');
        }

        while ($generator->valid()) {
            $item = $generator->current();

            if ($item instanceof \Generator) {
                yield from $this->executeGenerator(fn() => yield from $item, [
                    'concurrency' => $maxConcurrency,
                ]);
            } else {
                yield $item;
            }

            $generator->send(true);
        }

        return $generator->getReturn();
    }

    /**
     * Map 操作
     *
     * 对数组中的每个元素并行执行处理函数。
     *
     * @param array $items 要处理的元素数组
     * @param callable $processor 处理函数
     * @param callable|null $progress 进度回调
     * @return array 处理结果数组
     */
    public function map(array $items, callable $processor, ?callable $progress = null): array
    {
        $tasks = array_map(fn($item) => fn() => $processor($item), $items);

        return $this->executeBatch($tasks, $progress);
    }

    /**
     * 等待所有任务完成
     */
    public function waitAll(): void
    {
        $this->pool->run();
    }

    /**
     * 检查是否启用了并行模式
     */
    public function isParallelEnabled(): bool
    {
        return $this->enableParallel;
    }

    /**
     * 获取并发数
     */
    public function concurrency(): int
    {
        return $this->concurrency;
    }

    /**
     * 顺序执行（降级模式）
     *
     * @param array $tasks 任务函数数组
     * @return array 任务结果数组
     */
    private function executeSequentially(array $tasks): array
    {
        $results = [];

        foreach ($tasks as $index => $task) {
            try {
                $results[$index] = $task();
            } catch (\Throwable $e) {
                LogManager::error('顺序执行失败', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
                $results[$index] = null;
            }
        }

        return $results;
    }

    /**
     * 带进度回调的执行
     *
     * @param AsyncTask[] $tasks 异步任务数组
     * @param callable $progress 进度回调
     * @return array 任务结果数组
     */
    private function executeWithProgress(array $tasks, callable $progress): array
    {
        $total = count($tasks);
        $completed = 0;

        $this->pool->run();

        $results = [];
        foreach ($tasks as $index => $task) {
            try {
                $results[$index] = $task->getResult();
            } catch (\Throwable $e) {
                LogManager::error('任务执行失败', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
                $results[$index] = null;
            }

            $completed++;
            $progress($completed, $total);
        }

        return $results;
    }
}