<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Kode\Context\Context as KodeContext;

/**
 * 任务执行上下文
 *
 * 管理单个任务的生命周期，包括超时控制、进度追踪、重试机制等。
 * 集成 kode/context 实现协程安全的上下文存储。
 *
 * @package Kode\AiAgent\Agent
 */
final class ExecutionContext
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_CANCELLED = 'cancelled';

    private const CONTEXT_HISTORY_KEY = 'ai_agent.execution_history';
    private const MAX_HISTORY_SIZE = 100;

    private string $id;
    private string $status = self::STATUS_PENDING;
    private int $attempts = 0;
    private int $maxAttempts = 3;
    private float $startTime = 0;
    private float $endTime = 0;
    private float $timeoutSeconds = 60;
    private array $metadata = [];
    private array $errors = [];
    private array $artifacts = [];
    private bool $useContextStorage = false;

    public function __construct(
        string $id,
        private string $task,
        private string $role,
        private array $options = [],
    ) {
        $this->id = $id;
        $this->maxAttempts = $options['max_attempts'] ?? 3;
        $this->timeoutSeconds = $options['timeout'] ?? 60;
        $this->metadata = $options['metadata'] ?? [];
        $this->useContextStorage = $options['use_context_storage'] ?? false;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function task(): string
    {
        return $this->task;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function timeoutSeconds(): float
    {
        return $this->timeoutSeconds;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function artifacts(): array
    {
        return $this->artifacts;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function duration(): float
    {
        if ($this->startTime === 0) {
            return 0;
        }

        $end = $this->endTime ?: microtime(true);
        return $end - $this->startTime;
    }

    public function isTimeout(): bool
    {
        if ($this->startTime === 0 || $this->status !== self::STATUS_RUNNING) {
            return false;
        }

        return (microtime(true) - $this->startTime) > $this->timeoutSeconds;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_TIMEOUT,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function canRetry(): bool
    {
        return $this->attempts < $this->maxAttempts
            && $this->status === self::STATUS_FAILED;
    }

    public function start(): self
    {
        $this->status = self::STATUS_RUNNING;
        $this->startTime = microtime(true);
        $this->attempts++;
        return $this;
    }

    public function complete(array $artifacts = []): self
    {
        $this->status = self::STATUS_COMPLETED;
        $this->endTime = microtime(true);
        $this->artifacts = $artifacts;
        $this->persistToHistory();
        return $this;
    }

    public function fail(string $error): self
    {
        $this->status = self::STATUS_FAILED;
        $this->endTime = microtime(true);
        $this->errors[] = [
            'attempt' => $this->attempts,
            'error' => $error,
            'timestamp' => microtime(true),
        ];
        $this->persistToHistory();
        return $this;
    }

    public function markTimeout(): self
    {
        $this->status = self::STATUS_TIMEOUT;
        $this->endTime = microtime(true);
        $this->persistToHistory();
        return $this;
    }

    public function cancel(): self
    {
        $this->status = self::STATUS_CANCELLED;
        $this->endTime = microtime(true);
        $this->persistToHistory();
        return $this;
    }

    public function addArtifact(string $key, mixed $value): self
    {
        $this->artifacts[$key] = $value;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'task' => $this->task,
            'role' => $this->role,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'timeout' => $this->timeoutSeconds,
            'duration' => round($this->duration(), 3),
            'errors' => $this->errors,
            'artifacts' => $this->artifacts,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * 持久化到执行历史（协程安全）
     */
    private function persistToHistory(): void
    {
        if (!$this->useContextStorage) {
            return;
        }

        $history = KodeContext::get(self::CONTEXT_HISTORY_KEY, []);
        $history[] = $this->toArray();

        if (count($history) > self::MAX_HISTORY_SIZE) {
            $history = array_slice($history, -self::MAX_HISTORY_SIZE);
        }

        KodeContext::set(self::CONTEXT_HISTORY_KEY, $history);
    }

    /**
     * 从协程安全的上下文存储获取执行历史
     */
    public static function getHistoryFromContext(): array
    {
        return KodeContext::get(self::CONTEXT_HISTORY_KEY, []);
    }

    /**
     * 清除执行历史
     */
    public static function clearHistory(): void
    {
        KodeContext::delete(self::CONTEXT_HISTORY_KEY);
    }

    /**
     * 在新的执行上下文中执行回调
     */
    public static function run(callable $callable): mixed
    {
        return KodeContext::run(function () use ($callable) {
            $context = new self(
                id: sprintf('ctx-%s-%s', date('Ymd-His'), bin2hex(random_bytes(4))),
                task: 'temporary',
                role: 'temporary',
            );
            $context->useContextStorage = true;
            return $callable($context);
        });
    }

    /**
     * 创建一个新的执行上下文（支持协程存储）
     */
    public static function create(
        string $id,
        string $task,
        string $role,
        array $options = []
    ): self {
        $context = new self($id, $task, $role, $options);

        if (($options['use_context_storage'] ?? false)
            || KodeContext::has('ai_agent.execution_history')) {
            $context->useContextStorage = true;
        }

        return $context;
    }
}
