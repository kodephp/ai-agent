<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Resilience\CircuitBreaker;
use Kode\AiAgent\Resilience\CircuitOpenException;
use PHPUnit\Framework\TestCase;

/**
 * 熔断器测试
 */
final class CircuitBreakerTest extends TestCase
{
    public function testStartsClosed(): void
    {
        $breaker = new CircuitBreaker();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $breaker->state());
    }

    public function testSuccessfulCall(): void
    {
        $breaker = new CircuitBreaker();
        $result = $breaker->call(fn() => 'success');
        $this->assertSame('success', $result);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $breaker->state());
    }

    public function testOpensAfterThreshold(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 3);

        for ($i = 0; $i < 3; $i++) {
            try {
                $breaker->call(fn() => throw new \RuntimeException('fail'));
            } catch (\RuntimeException) {
                // expected
            }
        }

        $this->assertSame(CircuitBreaker::STATE_OPEN, $breaker->state());

        $this->expectException(CircuitOpenException::class);
        $breaker->call(fn() => 'should not run');
    }

    public function testHalfOpenAfterCooldown(): void
    {
        $breaker = new CircuitBreaker(
            failureThreshold: 2,
            cooldownSeconds: 1,
        );

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(fn() => throw new \RuntimeException('fail'));
            } catch (\RuntimeException) {
            }
        }

        $this->assertSame(CircuitBreaker::STATE_OPEN, $breaker->state());

        // 等待冷却到期
        sleep(2);
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $breaker->state());
    }

    public function testReset(): void
    {
        $breaker = new CircuitBreaker(failureThreshold: 2);

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(fn() => throw new \RuntimeException('fail'));
            } catch (\RuntimeException) {
            }
        }

        $this->assertSame(CircuitBreaker::STATE_OPEN, $breaker->state());
        $breaker->reset();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $breaker->state());
    }

    public function testStateChangeCallback(): void
    {
        $transitions = [];
        $breaker = new CircuitBreaker(
            failureThreshold: 2,
            onStateChange: function (string $from, string $to) use (&$transitions) {
                $transitions[] = "{$from}->{$to}";
            },
        );

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(fn() => throw new \RuntimeException('fail'));
            } catch (\RuntimeException) {
            }
        }

        $this->assertContains('closed->open', $transitions);
    }
}
