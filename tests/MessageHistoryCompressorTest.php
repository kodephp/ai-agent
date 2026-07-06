<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Token\MessageHistoryCompressor;
use Kode\AiAgent\Token\TokenCounter;
use PHPUnit\Framework\TestCase;

/**
 * 消息历史压缩器测试
 */
final class MessageHistoryCompressorTest extends TestCase
{
    private MessageHistoryCompressor $compressor;

    protected function setUp(): void
    {
        $this->compressor = new MessageHistoryCompressor(new TokenCounter());
    }

    public function testCompressPreservesSystemMessages(): void
    {
        $messages = [
            ['role' => 'system', 'content' => '你是一个助手'],
            ['role' => 'user', 'content' => '你好'],
            ['role' => 'assistant', 'content' => '你好，有什么可以帮你的？'],
            ['role' => 'user', 'content' => '今天天气怎样？'],
        ];

        $compressed = $this->compressor->compress($messages, maxTokens: 50);

        // 系统消息必须保留
        $roles = array_column($compressed, 'role');
        $this->assertContains('system', $roles);
    }

    public function testCompressRemovesOlderMessages(): void
    {
        $messages = [
            ['role' => 'system', 'content' => '你是一个助手'],
            ['role' => 'user', 'content' => str_repeat('旧消息1 ', 50)],
            ['role' => 'user', 'content' => str_repeat('旧消息2 ', 50)],
            ['role' => 'user', 'content' => '最新消息'],
        ];

        $compressed = $this->compressor->compress($messages, maxTokens: 50);
        $this->assertLessThan(count($messages), count($compressed));
    }

    public function testSlidingWindow(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'msg1'],
            ['role' => 'user', 'content' => 'msg2'],
            ['role' => 'user', 'content' => 'msg3'],
            ['role' => 'user', 'content' => 'msg4'],
        ];

        $result = $this->compressor->slidingWindow($messages, windowSize: 2);

        // 应该有 system + 最后 2 条
        $this->assertCount(3, $result);
        $this->assertSame('system', $result[0]['role']);
        $this->assertSame('msg3', $result[1]['content']);
        $this->assertSame('msg4', $result[2]['content']);
    }

    public function testSavingsReport(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => str_repeat('a', 1000)],
            ['role' => 'user', 'content' => str_repeat('b', 1000)],
            ['role' => 'user', 'content' => '最近消息'],
        ];

        $savings = $this->compressor->savings($messages, maxTokens: 30);

        $this->assertArrayHasKey('original', $savings);
        $this->assertArrayHasKey('compressed', $savings);
        $this->assertGreaterThan(0, $savings['saved']);
    }
}
