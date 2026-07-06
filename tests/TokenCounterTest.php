<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Token\TokenCounter;
use PHPUnit\Framework\TestCase;

/**
 * Token 计数器测试
 */
final class TokenCounterTest extends TestCase
{
    private TokenCounter $counter;

    protected function setUp(): void
    {
        $this->counter = new TokenCounter();
    }

    public function testEmptyStringReturnsZero(): void
    {
        $this->assertSame(0, $this->counter->estimate(''));
    }

    public function testChineseText(): void
    {
        $tokens = $this->counter->estimate('你好世界');
        $this->assertGreaterThan(0, $tokens);
        $this->assertLessThan(10, $tokens);
    }

    public function testEnglishText(): void
    {
        $tokens = $this->counter->estimate('Hello world');
        $this->assertGreaterThan(0, $tokens);
        $this->assertLessThan(10, $tokens);
    }

    public function testMixedText(): void
    {
        $tokens = $this->counter->estimate('Hello 你好 World 世界');
        $this->assertGreaterThan(2, $tokens);
    }

    public function testBatchEstimation(): void
    {
        $total = $this->counter->batch(['Hello', 'World', '你好']);
        $this->assertGreaterThan(0, $total);
    }

    public function testMessagesEstimation(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant'],
            ['role' => 'user', 'content' => 'Hello'],
        ];
        $tokens = $this->counter->messages($messages);
        $this->assertGreaterThan(0, $tokens);
    }
}
