<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Closure;

/**
 * 重试策略
 *
 * 提供灵活的重试机制，支持指数退避、自定义重试条件等。
 *
 * @package Kode\AiAgent\Agent
 */
final class RetryStrategy
{
    public const STRATEGY_FIXED = 'fixed';
    public const STRATEGY_EXPONENTIAL = 'exponential';
    public const STRATEGY_LINEAR = 'linear';

    private int $maxAttempts = 3;
    private int $baseDelayMs = 1000;
    private int $maxDelayMs = 30000;
    private float $multiplier = 2.0;
    private string $strategy = self::STRATEGY_EXPONENTIAL;
    private array $retryableErrors = [];
    private ?Closure $shouldRetryCallback = null;

    public function __construct(array $options = [])
    {
        $this->maxAttempts = $options['max_attempts'] ?? 3;
        $this->baseDelayMs = $options['base_delay_ms'] ?? 1000;
        $this->maxDelayMs = $options['max_delay_ms'] ?? 30000;
        $this->multiplier = $options['multiplier'] ?? 2.0;
        $this->strategy = $options['strategy'] ?? self::STRATEGY_EXPONENTIAL;
        $this->retryableErrors = $options['retryable_errors'] ?? [];
    }

    /**
     * 创建默认重试策略
     */
    public static function default(): self
    {
        return new self([
            'max_attempts' => 3,
            'base_delay_ms' => 1000,
            'strategy' => self::STRATEGY_EXPONENTIAL,
        ]);
    }

    /**
     * 创建快速重试策略（无延迟）
     */
    public static function fast(): self
    {
        return new self([
            'max_attempts' => 3,
            'base_delay_ms' => 0,
            'strategy' => self::STRATEGY_FIXED,
        ]);
    }

    /**
     * 创建保守重试策略（长延迟）
     */
    public static function conservative(): self
    {
        return new self([
            'max_attempts' => 5,
            'base_delay_ms' => 2000,
            'max_delay_ms' => 60000,
            'strategy' => self::STRATEGY_EXPONENTIAL,
        ]);
    }

    /**
     * 设置最大重试次数
     */
    public function maxAttempts(int $attempts): self
    {
        $this->maxAttempts = $attempts;
        return $this;
    }

    /**
     * 设置基础延迟（毫秒）
     */
    public function baseDelay(int $ms): self
    {
        $this->baseDelayMs = $ms;
        return $this;
    }

    /**
     * 设置最大延迟（毫秒）
     */
    public function maxDelay(int $ms): self
    {
        $this->maxDelayMs = $ms;
        return $this;
    }

    /**
     * 设置退避策略
     */
    public function strategy(string $strategy): self
    {
        $this->strategy = $strategy;
        return $this;
    }

    /**
     * 设置可重试的错误类型
     */
    public function retryableErrors(array $errors): self
    {
        $this->retryableErrors = $errors;
        return $this;
    }

    /**
     * 设置自定义重试判断回调
     */
    public function withRetryCondition(Closure $callback): self
    {
        $this->shouldRetryCallback = $callback;
        return $this;
    }

    /**
     * 计算重试延迟（毫秒）
     */
    public function calculateDelay(int $attempt): int
    {
        $delay = match ($this->strategy) {
            self::STRATEGY_FIXED => $this->baseDelayMs,
            self::STRATEGY_LINEAR => $this->baseDelayMs * $attempt,
            self::STRATEGY_EXPONENTIAL => (int) ($this->baseDelayMs * pow($this->multiplier, $attempt - 1)),
            default => $this->baseDelayMs,
        };

        return min($delay, $this->maxDelayMs);
    }

    /**
     * 检查是否应该重试
     */
    public function shouldRetry(int $attempt, ?\Throwable $error = null): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        if ($this->shouldRetryCallback !== null) {
            return ($this->shouldRetryCallback)($attempt, $error);
        }

        if ($error !== null && !empty($this->retryableErrors)) {
            $errorClass = get_class($error);
            foreach ($this->retryableErrors as $retryableError) {
                if ($errorClass === $retryableError || is_a($error, $retryableError, true)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    /**
     * 执行重试
     */
    public function execute(callable $operation): mixed
    {
        $attempt = 0;
        $lastError = null;

        while (true) {
            $attempt++;

            try {
                return $operation($attempt);
            } catch (\Throwable $e) {
                $lastError = $e;

                if (!$this->shouldRetry($attempt, $e)) {
                    throw $e;
                }

                $delay = $this->calculateDelay($attempt);
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        }
    }

    /**
     * 获取配置
     */
    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'base_delay_ms' => $this->baseDelayMs,
            'max_delay_ms' => $this->maxDelayMs,
            'multiplier' => $this->multiplier,
            'strategy' => $this->strategy,
            'retryable_errors' => $this->retryableErrors,
        ];
    }
}
