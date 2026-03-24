<?php

declare(strict_types=1);

namespace Kode\AiAgent\Async;

use Kode\AiAgent\Log\LogManager;
use Generator;

final class ParallelExecutor
{
    private FiberPool $pool;
    private int $concurrency;
    private bool $enableParallel;

    public function __construct(int $concurrency = 10, bool $enableParallel = true)
    {
        $this->concurrency = $concurrency;
        $this->enableParallel = $enableParallel && extension_loaded('Fiber');
        $this->pool = new FiberPool($this->enableParallel ? $concurrency : 1);
    }

    public function execute(callable $task): AsyncTask
    {
        return $this->pool->submit($task);
    }

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

    public function executeGenerator(callable $task, array $options = []): Generator
    {
        $maxConcurrency = $options['concurrency'] ?? $this->concurrency;

        $generator = $task();

        if (!$generator instanceof Generator) {
            throw new \InvalidArgumentException('Task must return a Generator');
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

    public function map(array $items, callable $processor, ?callable $progress = null): array
    {
        $tasks = array_map(fn($item) => fn() => $processor($item), $items);

        return $this->executeBatch($tasks, $progress);
    }

    public function waitAll(): void
    {
        $this->pool->run();
    }

    public function isParallelEnabled(): bool
    {
        return $this->enableParallel;
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    private function executeSequentially(array $tasks): array
    {
        $results = [];

        foreach ($tasks as $index => $task) {
            try {
                $results[$index] = $task();
            } catch (\Throwable $e) {
                LogManager::error('Sequential execution failed', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
                $results[$index] = null;
            }
        }

        return $results;
    }

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
                LogManager::error('Task execution failed', [
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