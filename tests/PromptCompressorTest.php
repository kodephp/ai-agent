<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Token\PromptCompressor;
use Kode\AiAgent\Token\TokenCounter;
use PHPUnit\Framework\TestCase;

/**
 * Prompt 压缩器测试
 */
final class PromptCompressorTest extends TestCase
{
    private PromptCompressor $compressor;

    protected function setUp(): void
    {
        $this->compressor = new PromptCompressor(new TokenCounter());
    }

    public function testCompressSynonyms(): void
    {
        $original = '请帮我写一个故事';
        $compressed = $this->compressor->compress($original);

        $this->assertStringNotContainsString('请帮我', $compressed);
        $this->assertStringContainsString('请', $compressed);
    }

    public function testStripCourtesy(): void
    {
        $original = '请回答问题，谢谢！';
        $compressed = $this->compressor->compress($original);

        $this->assertStringNotContainsString('谢谢', $compressed);
    }

    public function testNormalizeWhitespace(): void
    {
        $original = "hello    world\n\n\n\nfoo";
        $compressed = $this->compressor->compress($original);

        $this->assertStringNotContainsString("    ", $compressed);
        $this->assertStringNotContainsString("\n\n\n\n", $compressed);
    }

    public function testStripMarkdown(): void
    {
        $original = '## 标题\n**加粗内容** 正常文本';
        $compressed = $this->compressor->compress($original);

        $this->assertStringNotContainsString('##', $compressed);
        $this->assertStringNotContainsString('**', $compressed);
    }

    public function testMaxTokensTruncation(): void
    {
        $longText = str_repeat('这是一段测试文本，用于验证压缩功能是否正常工作。', 50);
        $compressed = $this->compressor->compress($longText, maxTokens: 100);

        $counter = new TokenCounter();
        $originalTokens = $counter->estimate($longText);
        $compressedTokens = $counter->estimate($compressed);

        $this->assertLessThanOrEqual(120, $compressedTokens);
        $this->assertLessThan($originalTokens, $compressedTokens);
    }

    public function testSavingsReport(): void
    {
        $original = '请帮我写一个非常非常非常重要的故事，谢谢';
        $savings = $this->compressor->savings($original);

        $this->assertArrayHasKey('original', $savings);
        $this->assertArrayHasKey('compressed', $savings);
        $this->assertArrayHasKey('saved', $savings);
        $this->assertArrayHasKey('ratio', $savings);
        $this->assertGreaterThan(0, $savings['saved']);
    }
}
