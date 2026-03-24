<?php

declare(strict_types=1);

namespace Kode\AiAgent\Process;

use Kode\AiAgent\Log\LogManager;

final class Process
{
    private string $command;
    private array $options;
    private ?int $pid = null;
    private ?resource $handle = null;
    private string $output = '';
    private string $errorOutput = '';
    private string $status = 'pending';
    private int $exitCode = -1;
    private float $startTime = 0;
    private float $endTime = 0;

    public function __construct(string $command, array $options = [])
    {
        $this->command = $command;
        $this->options = array_merge([
            'cwd' => null,
            'env' => null,
            'timeout' => 300,
            'buffer_size' => 8192,
        ], $options);
    }

    public function start(): self
    {
        if ($this->status !== 'pending') {
            throw new \RuntimeException("Process has already been started (status: {$this->status})");
        }

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = $this->options['cwd'] ?? null;
        $env = $this->options['env'] ?? null;

        $this->handle = proc_open(
            $this->command,
            $descriptor,
            $pipes,
            $cwd,
            $env
        );

        if (!is_resource($this->handle)) {
            throw new \RuntimeException("Failed to start process: {$this->command}");
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $this->pid = proc_get_status($this->handle)['pid'];
        $this->status = 'running';
        $this->startTime = microtime(true);

        LogManager::info('Process started', [
            'pid' => $this->pid,
            'command' => $this->command,
        ]);

        return $this;
    }

    public function update(): ?string
    {
        if ($this->status !== 'running' || !is_resource($this->handle)) {
            return null;
        }

        $output = '';
        $pipes = proc_get_status($this->handle);

        if ($pipes['pipe']) {
            while ($data = fread($pipes['pipe'], $this->options['buffer_size'])) {
                $output .= $data;
            }
        }

        if ($pipes['pipe']) {
            while ($data = fread($pipes['pipe'], $this->options['buffer_size'])) {
                $this->errorOutput .= $data;
            }
        }

        if (!$pipes['running']) {
            $this->status = 'completed';
            $this->exitCode = $pipes['exitcode'];
            $this->endTime = microtime(true);

            LogManager::info('Process completed', [
                'pid' => $this->pid,
                'exit_code' => $this->exitCode,
                'duration' => $this->duration(),
            ]);
        }

        return $output;
    }

    public function wait(): self
    {
        if ($this->status !== 'running') {
            return $this;
        }

        while ($this->isRunning()) {
            $this->update();
            usleep(1000);
        }

        return $this;
    }

    public function terminate(): self
    {
        if ($this->status === 'running' && is_resource($this->handle)) {
            proc_terminate($this->handle, SIGTERM);
            $this->status = 'terminated';
            $this->endTime = microtime(true);

            LogManager::warning('Process terminated', ['pid' => $this->pid]);
        }

        return $this;
    }

    public function kill(): self
    {
        if ($this->status === 'running' && is_resource($this->handle)) {
            proc_terminate($this->handle, SIGKILL);
            $this->status = 'killed';
            $this->endTime = microtime(true);

            LogManager::error('Process killed', ['pid' => $this->pid]);
        }

        return $this;
    }

    public function isRunning(): bool
    {
        return $this->status === 'running' && is_resource($this->handle);
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    public function duration(): float
    {
        if ($this->startTime === 0) {
            return 0;
        }

        $end = $this->endTime ?: microtime(true);
        return round($end - $this->startTime, 3);
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            foreach ([0, 1, 2] as $fd) {
                $pipe = fopen("php://fd/{$fd}", 'r');
                if ($pipe !== false) {
                    fclose($pipe);
                }
            }
            proc_close($this->handle);
        }
    }
}