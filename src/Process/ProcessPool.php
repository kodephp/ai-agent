<?php

declare(strict_types=1);

namespace Kode\AiAgent\Process;

use Kode\AiAgent\Log\LogManager;

final class ProcessPool
{
    /** @var Process[] */
    private array $processes = [];
    private int $maxProcesses;
    private int $activeCount = 0;

    public function __construct(int $maxProcesses = 4)
    {
        $this->maxProcesses = max(1, $maxProcesses);
    }

    public function submit(string $command, array $options = []): Process
    {
        $process = new Process($command, $options);
        $this->processes[] = $process;

        return $process;
    }

    public function submitBatch(array $commands): array
    {
        return array_map(fn($cmd) => $this->submit($cmd), $commands);
    }

    public function run(callable $onOutput = null): void
    {
        foreach ($this->processes as $process) {
            $process->start();

            if ($this->activeCount >= $this->maxProcesses) {
                $this->waitForAvailable();
            }

            $this->activeCount++;
        }

        $this->waitForAll($onOutput);
    }

    public function runAndWait(callable $onOutput = null): array
    {
        $this->run($onOutput);

        return array_map(fn($p) => $p->getOutput(), $this->processes);
    }

    public function isRunning(): bool
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                return true;
            }
        }

        return false;
    }

    public function waitForAll(callable $onOutput = null): void
    {
        while ($this->isRunning()) {
            foreach ($this->processes as $process) {
                if ($process->isRunning()) {
                    $output = $process->update();

                    if ($output !== null && $onOutput !== null) {
                        $onOutput($process->getPid(), $output);
                    }
                }
            }

            if ($this->activeCount >= $this->maxProcesses && $this->isRunning()) {
                $this->waitForCompletion();
            }
        }
    }

    public function terminate(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->terminate();
            }
        }
    }

    public function count(): int
    {
        return count($this->processes);
    }

    public function activeCount(): int
    {
        $count = 0;

        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $count++;
            }
        }

        return $count;
    }

    private function waitForAvailable(): void
    {
        while ($this->activeCount >= $this->maxProcesses) {
            foreach ($this->processes as $process) {
                if (!$process->isRunning() && $process->getStatus() === 'completed') {
                    $this->activeCount--;
                    return;
                }
            }

            usleep(10000);
        }
    }

    private function waitForCompletion(): void
    {
        $startTime = microtime(true);
        $timeout = 300.0;

        while ($this->activeCount >= $this->maxProcesses && (microtime(true) - $startTime) < $timeout) {
            foreach ($this->processes as $process) {
                if (!$process->isRunning()) {
                    $this->activeCount--;
                    return;
                }
            }

            usleep(10000);
        }

        if ((microtime(true) - $startTime) >= $timeout) {
            LogManager::warning('ProcessPool timeout waiting for completion');
        }
    }
}