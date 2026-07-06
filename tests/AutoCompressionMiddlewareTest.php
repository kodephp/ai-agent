<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Model\Prompt;
use Kode\AiAgent\Moe\AutoCompressionMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * 自动压缩中间件测试
 */
final class AutoCompressionMiddlewareTest extends TestCase
{
    public function testCompressesLongPrompt(): void
    {
        $middleware = new AutoCompressionMiddleware(
            threshold: 50,
            targetRatio: 0.5,
            minTokens: 10,
        );

        $longText = str_repeat('请帮我写一个故事，非常感谢。', 20);
        $prompt = new Prompt($longText);
        $compressed = $middleware->compress($prompt);

        $originalTokens = (new \Kode\AiAgent\Token\TokenCounter())->estimate($longText);
        $compressedTokens = (new \Kode\AiAgent\Token\TokenCounter())->estimate($compressed->text());

        $this->assertLessThan($originalTokens, $compressedTokens);
    }

    public function testSkipsShortPrompt(): void
    {
        $middleware = new AutoCompressionMiddleware(
            threshold: 1000,
            targetRatio: 0.5,
        );

        $shortText = '你好';
        $prompt = new Prompt($shortText);
        $compressed = $middleware->compress($prompt);

        $this->assertSame($shortText, $compressed->text());
    }

    public function testDisabledMiddlewareReturnsOriginal(): void
    {
        $middleware = new AutoCompressionMiddleware(
            threshold: 10,
            targetRatio: 0.5,
            enabled: false,
        );

        $text = str_repeat('请帮我写一个故事，非常感谢。', 20);
        $prompt = new Prompt($text);
        $compressed = $middleware->compress($prompt);

        $this->assertSame($text, $compressed->text());
    }

    public function testSavingsReportForLongPrompt(): void
    {
        $middleware = new AutoCompressionMiddleware(
            threshold: 50,
            targetRatio: 0.5,
        );

        $longText = str_repeat('请帮我写一个故事，非常感谢。', 20);
        $prompt = new Prompt($longText);
        $savings = $middleware->savings($prompt);

        $this->assertGreaterThan(0, $savings['saved']);
        $this->assertGreaterThan(0.0, $savings['ratio']);
    }

    public function testSavingsReportForShortPrompt(): void
    {
        $middleware = new AutoCompressionMiddleware(
            threshold: 1000,
            targetRatio: 0.5,
        );

        $prompt = new Prompt('你好');
        $savings = $middleware->savings($prompt);

        $this->assertSame(0, $savings['saved']);
        $this->assertSame(0.0, $savings['ratio']);
    }
}
