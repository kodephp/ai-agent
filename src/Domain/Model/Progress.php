<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Model;

/**
 * 进度跟踪模型
 * 
 * 用于跟踪媒体生成任务的执行进度。
 * 
 * @package Kode\AiAgent\Domain\Model
 */
readonly class Progress
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public float $createdAt;
    public float $updatedAt;

    public function __construct(
        public string $taskId,
        public string $status,
        public int $progress = 0,
        public string $message = '',
        public ?array $data = null,
        float $createdAt = 0.0,
        float $updatedAt = 0.0,
    ) {
        $this->createdAt = $createdAt === 0.0 ? microtime(true) : $createdAt;
        $this->updatedAt = $updatedAt === 0.0 ? microtime(true) : $updatedAt;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isUploading(): bool
    {
        return $this->status === self::STATUS_UPLOADING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isGenerating(): bool
    {
        return $this->status === self::STATUS_GENERATING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isTerminal(): bool
    {
        return $this->isCompleted() || $this->isFailed();
    }

    public function withProgress(int $progress, string $message = ''): self
    {
        return new self(
            taskId: $this->taskId,
            status: $this->status,
            progress: max(0, min(100, $progress)),
            message: $message ?: $this->message,
            data: $this->data,
            createdAt: $this->createdAt,
            updatedAt: microtime(true),
        );
    }

    public function withStatus(string $status, string $message = '', ?array $data = null): self
    {
        return new self(
            taskId: $this->taskId,
            status: $status,
            progress: $status === self::STATUS_COMPLETED ? 100 : $this->progress,
            message: $message ?: $this->message,
            data: $data ?? $this->data,
            createdAt: $this->createdAt,
            updatedAt: microtime(true),
        );
    }

    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'status' => $this->status,
            'progress' => $this->progress,
            'message' => $this->message,
            'data' => $this->data,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'elapsed' => $this->updatedAt - $this->createdAt,
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_UNESCAPED_UNICODE);
    }

    public static function create(string $taskId, string $message = ''): self
    {
        return new self(
            taskId: $taskId,
            status: self::STATUS_PENDING,
            progress: 0,
            message: $message ?: '任务已创建，等待处理',
        );
    }
}
