<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Moe\TokenBudget;
use PHPUnit\Framework\TestCase;

/**
 * Token 预算测试
 */
final class TokenBudgetTest extends TestCase
{
    public function testUnlimitedBudget(): void
    {
        $budget = new TokenBudget();
        $this->assertTrue($budget->canConsume(10000, 5000));
        $budget->consume(10000, 5000, 0.05);

        $totals = $budget->totals();
        $this->assertSame(10000, $totals['prompt_tokens']);
        $this->assertSame(5000, $totals['completion_tokens']);
        $this->assertEqualsWithDelta(0.05, $totals['total_cost'], 0.0001);
    }

    public function testPerMinuteLimit(): void
    {
        $budget = new TokenBudget(perMinute: 1000);
        $this->assertTrue($budget->canConsume(500, 400));
        $this->assertFalse($budget->canConsume(600, 500));
    }

    public function testPerDayLimit(): void
    {
        $budget = new TokenBudget(perDay: 10000);
        $budget->consume(5000, 3000, 0.1);
        $this->assertTrue($budget->canConsume(1000, 500));
        $this->assertFalse($budget->canConsume(3000, 2000));
    }

    public function testConsumeExceedsLimit(): void
    {
        $budget = new TokenBudget(perDay: 1000);
        $budget->consume(500, 200, 0.01);

        $this->expectException(\RuntimeException::class);
        $budget->consume(500, 200, 0.01);
    }

    public function testMonthlyCostLimit(): void
    {
        $budget = new TokenBudget(perMonthCost: 1.0);
        $budget->consume(100, 100, 0.5);
        // 剩余 0.5，成本 0.4 可消费
        $this->assertTrue($budget->canConsume(100, 100, 0.4));
        // 剩余 0.5，成本 0.6 不可消费
        $this->assertFalse($budget->canConsume(100, 100, 0.6));
    }

    public function testRemaining(): void
    {
        $budget = new TokenBudget(perMinute: 1000, perDay: 10000);
        $budget->consume(100, 50, 0.001);

        $remaining = $budget->remaining();
        $this->assertArrayHasKey('per_minute', $remaining);
        $this->assertArrayHasKey('per_day', $remaining);
        $this->assertSame(1000, $remaining['per_minute']['limit']);
        $this->assertSame(150, $remaining['per_minute']['used']);
        $this->assertSame(850, $remaining['per_minute']['remaining']);
    }

    public function testReset(): void
    {
        $budget = new TokenBudget(perMinute: 1000);
        $budget->consume(500, 200, 0.01);
        $budget->reset();

        $totals = $budget->totals();
        $this->assertSame(0, $totals['prompt_tokens']);
        $this->assertSame(0, $totals['total_tokens']);
    }
}
