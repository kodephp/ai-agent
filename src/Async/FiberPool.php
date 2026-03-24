<?php

declare(strict_types=1);

namespace Kode\AiAgent\Async;

use Kode\AiAgent\Log\LogManager;

/**
 * Fiber 并发池
 *
 * 管理多个异步任务的并发执行，支持任务提交、批量执行、并发控制和结果回收。
 * 自动维护并发数量，防止资源耗尽。
 *
 * @package Kode\AiAgent\Async
 *
 * @example
 * ```php
 * $pool = new FiberPool(concurrency: 10);
 *
 * // 提交多个任务
 * for ($i = 0; $i < 20; $i++) {
 *     $pool->submit(fn() => processImage($i));
 * }
 *
 * // 执行并等待完成
 * $pool->runAndWait();
 * ```
 */
final class FiberPool
{
    /** @var AsyncTask[] 任务列表 */
    private array $tasks = [];
    private int $concurrency;
    private int $activeCount = 0;

    /**
     * 创建 Fiber 池
     *
     * @param int $concurrency 最大并发数，默认 10
     */
    public function __construct(int $concurrency = 10)
    {
        $this->concurrency = max(1, $concurrency);
    }

    /**
     * 提交单个任务
     *
     * @param callable $task 任务函数
     * @return AsyncTask 异步任务实例
     */
    public function submit(callable $task): AsyncTask
    {
        $asyncTask = new AsyncTask($task);
        $this->tasks[] = $asyncTask;

        return $asyncTask;
    }

    /**
     * 批量提交任务
     *
     * @param array $tasks 任务函数数组
     * @return AsyncTask[] 异步任务实例数组
     */
    public function submitBatch(array $tasks): array
    {
        return array_map(fn($task) => $this->submit($task), $tasks);
    }

    /**
     * 执行所有任务
     *
     * 启动所有待执行任务，当并发数达到上限时等待任务完成后再启动新任务。
     */
    public function run(): void
    {
        while (!$this->isEmpty()) {
            $this->processActive();

            if ($this->activeCount >= $this->concurrency) {
                $this->waitForCompletion();
            }

            $this->dispatchPending();
        }

        $this->processRemaining();
    }

    /**
     * 执行并等待所有任务完成
     *
     * @return array 所有任务的结果
     */
    public function runAndWait(): array
    {
        $this->run();

        return array_map(fn($task) => $task->getResult(), $this->tasks);
    }

    /**
     * 检查任务池是否为空
     */
    public function isEmpty(): bool
    {
        return empty($this->getPendingTasks()) && $this->activeCount === 0;
    }

    /**
     * 获取任务总数
     */
    public function count(): int
    {
        return count($this->tasks);
    }

    /**
     * 获取待执行任务数
     */
    public function pendingCount(): int
    {
        return count($this->getPendingTasks());
    }

    /**
     * 获取正在执行的任务数
     */
    public function activeCount(): int
    {
        return $this->activeCount;
    }

    /**
     * 获取待执行的任务列表
     *
     * @return AsyncTask[]
     */
    private function getPendingTasks(): array
    {
        return array_filter(
            $this->tasks,
            fn($task) => !$task->isStarted() && $task->getError() === null
        );
    }

    /**
     * 分发待执行任务
     */
    private function dispatchPending(): void
    {
        while ($this->activeCount < $this->concurrency) {
            $pending = $this->getPendingTasks();

            if (empty($pending)) {
                break;
            }

            $task = array_shift($pending);
            $task->start();
            $this->activeCount++;
        }
    }

    /**
     * 处理正在运行的任务
     */
    private function processActive(): void
    {
        foreach ($this->tasks as $task) {
            if ($task->isRunning()) {
                $this->processFiber($task);
            }
        }
    }

    /**
     * 处理单个 Fiber
     */
    private function processFiber(AsyncTask $task): void
    {
        if ($task->isSuspended()) {
            $task->resume();
        }

        if ($task->isTerminated()) {
            $this->activeCount--;
        }
    }

    /**
     * 等待任务完成
     *
     * 等待直到有任务完成，释放并发槽位。
     */
    private function waitForCompletion(): void
    {
        $startTime = microtime(true);
        $timeout = 30.0;

        while ($this->activeCount >= $this->concurrency) {
            foreach ($this->tasks as $task) {
                if ($task->isRunning()) {
                    $task->resume();
                }
            }

            if ($this->activeCount < $this->concurrency) {
                break;
            }

            if ((microtime(true) - $startTime) > $timeout) {
                LogManager::warning('FiberPool 等待超时', [
                    'active' => $this->activeCount,
                    'pending' => $this->pendingCount(),
                ]);
                break;
            }

            usleep(1000);
        }
    }

    /**
     * 处理剩余任务
     *
     * 确保所有已启动的任务都能正常完成。
     */
    private function processRemaining(): void
    {
        foreach ($this->tasks as $task) {
            if ($task->isStarted() && !$task->isTerminated()) {
                try {
                    while (!$task->isTerminated()) {
                        $task->resume();
                    }
                } catch (\Throwable $e) {
                    LogManager::error('FiberPool 任务执行错误', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}