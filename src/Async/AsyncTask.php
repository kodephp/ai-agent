<?php

declare(strict_types=1);

namespace Kode\AiAgent\Async;

use Fiber;

/**
 * 异步任务封装类
 *
 * 基于 PHP Fiber 实现的异步任务封装，支持任务的启动、恢复、挂起和结果获取。
 * 用于在协程环境中执行非阻塞任务。
 *
 * @package Kode\AiAgent\Async
 *
 * @example
 * ```php
 * $task = new AsyncTask(function() {
 *     // 模拟耗时操作
 *     Fiber::suspend('processing...');
 *     return 'result';
 * });
 *
 * $task->start();
 * $result = $task->getResult();
 * ```
 */
final class AsyncTask
{
    private Fiber $fiber;
    private ?\Throwable $error = null;
    private mixed $result = null;
    private bool $started = false;
    private bool $suspended = false;

    /**
     * 创建异步任务
     *
     * @param callable $task 任务执行函数
     */
    public function __construct(callable $task)
    {
        $this->fiber = new Fiber($task);
    }

    /**
     * 启动任务
     *
     * @return $this
     * @throws \RuntimeException 任务已启动时抛出
     */
    public function start(): self
    {
        if ($this->started) {
            throw new \RuntimeException('任务已启动');
        }

        $this->started = true;
        $this->resume();

        return $this;
    }

    /**
     * 恢复任务执行
     *
     * @param mixed $value 传递给 Fiber 的值
     * @return $this
     */
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

    /**
     * 挂起任务
     *
     * 挂起当前 Fiber 执行，将控制权返回给调用者。
     *
     * @param mixed $value 挂起时传递的值
     * @return mixed 调用 resume 时传递的值
     * @throws \RuntimeException Fiber 未运行时抛出
     */
    public function suspend(mixed $value = null): mixed
    {
        if (!$this->fiber->isRunning()) {
            throw new \RuntimeException('Fiber 未运行');
        }

        $this->suspended = true;
        return Fiber::suspend($value);
    }

    /**
     * 检查任务是否已启动
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * 检查任务是否正在运行
     */
    public function isRunning(): bool
    {
        return $this->fiber->isRunning();
    }

    /**
     * 检查任务是否已挂起
     */
    public function isSuspended(): bool
    {
        return $this->suspended && $this->fiber->isSuspended();
    }

    /**
     * 检查任务是否已终止
     */
    public function isTerminated(): bool
    {
        return $this->fiber->isTerminated();
    }

    /**
     * 获取任务结果
     *
     * @return mixed 任务返回值
     * @throws \Throwable 任务执行出错时抛出
     */
    public function getResult(): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->result;
    }

    /**
     * 获取任务错误
     */
    public function getError(): ?\Throwable
    {
        return $this->error;
    }

    /**
     * 向任务注入异常
     *
     * @param \Throwable $error 要注入的异常
     * @return $this
     */
    public function throw(\Throwable $error): self
    {
        $this->error = $error;

        if ($this->fiber->isStarted() && !$this->fiber->isTerminated()) {
            $this->fiber->throw($error);
        }

        return $this;
    }
}