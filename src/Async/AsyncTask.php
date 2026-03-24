<?php

declare(strict_types=1);

namespace Kode\AiAgent\Async;

use Fiber;

final class AsyncTask
{
    private Fiber $fiber;
    private ?\Throwable $error = null;
    private mixed $result = null;
    private bool $started = false;
    private bool $suspended = false;

    public function __construct(callable $task)
    {
        $this->fiber = new Fiber($task);
    }

    public function start(): self
    {
        if ($this->started) {
            throw new \RuntimeException('Task has already been started');
        }

        $this->started = true;
        $this->resume();

        return $this;
    }

    public function resume(mixed $value = null): self
    {
        if (!$this->fiber->isStarted()) {
            $this->fiber->start();
        } elseif ($this->fiber->isSuspended()) {
            $this->suspended = true;
            $this->fiber->resume($value);
        }

        if ($this->fiber->isTerminated()) {
            $this->result = $this->fiber->getReturn();
        }

        return $this;
    }

    public function suspend(mixed $value = null): mixed
    {
        if (!$this->fiber->isRunning()) {
            throw new \RuntimeException('Fiber is not running');
        }

        $this->suspended = true;
        return Fiber::suspend($value);
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function isRunning(): bool
    {
        return $this->fiber->isRunning();
    }

    public function isSuspended(): bool
    {
        return $this->suspended && $this->fiber->isSuspended();
    }

    public function isTerminated(): bool
    {
        return $this->fiber->isTerminated();
    }

    public function getResult(): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->result;
    }

    public function getError(): ?\Throwable
    {
        return $this->error;
    }

    public function throw(\Throwable $error): self
    {
        $this->error = $error;

        if ($this->fiber->isStarted() && !$this->fiber->isTerminated()) {
            $this->fiber->throw($error);
        }

        return $this;
    }
}