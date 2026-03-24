<?php

declare(strict_types=1);

namespace Kode\AiAgent\Async;

use Kode\AiAgent\Log\LogManager;

final class FiberPool
{
    /** @var AsyncTask[] */
    private array $tasks = [];
    private int $concurrency;
    private int $activeCount = 0;

    public function __construct(int $concurrency = 10)
    {
        $this->concurrency = max(1, $concurrency);
    }

    public function submit(callable $task): AsyncTask
    {
        $asyncTask = new AsyncTask($task);
        $this->tasks[] = $asyncTask;

        return $asyncTask;
    }

    public function submitBatch(array $tasks): array
    {
        return array_map(fn($task) => $this->submit($task), $tasks);
    }

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

    public function runAndWait(): array
    {
        $this->run();

        return array_map(fn($task) => $task->getResult(), $this->tasks);
    }

    public function isEmpty(): bool
    {
        return empty($this->getPendingTasks()) && $this->activeCount === 0;
    }

    public function count(): int
    {
        return count($this->tasks);
    }

    public function pendingCount(): int
    {
        return count($this->getPendingTasks());
    }

    public function activeCount(): int
    {
        return $this->activeCount;
    }

    private function getPendingTasks(): array
    {
        return array_filter(
            $this->tasks,
            fn($task) => !$task->isStarted() && $task->getError() === null
        );
    }

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

    private function processActive(): void
    {
        foreach ($this->tasks as $task) {
            if ($task->isRunning()) {
                $this->processFiber($task);
            }
        }
    }

    private function processFiber(AsyncTask $task): void
    {
        if ($task->isSuspended()) {
            $task->resume();
        }

        if ($task->isTerminated()) {
            $this->activeCount--;
        }
    }

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
                LogManager::warning('FiberPool wait timeout', [
                    'active' => $this->activeCount,
                    'pending' => $this->pendingCount(),
                ]);
                break;
            }

            usleep(1000);
        }
    }

    private function processRemaining(): void
    {
        foreach ($this->tasks as $task) {
            if ($task->isStarted() && !$task->isTerminated()) {
                try {
                    while (!$task->isTerminated()) {
                        $task->resume();
                    }
                } catch (\Throwable $e) {
                    LogManager::error('FiberPool task error', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}