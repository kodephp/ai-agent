<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Token\Skill\{
    CompressionSkillInterface,
    CourtesyRemovalSkill,
    MarkdownStripSkill,
    SynonymReplacementSkill,
    WhitespaceNormalizeSkill,
};
use Kode\AiAgent\Token\SkillBasedCompressor;
use PHPUnit\Framework\TestCase;

/**
 * 基于技能的 Prompt 压缩器测试
 */
final class SkillBasedCompressorTest extends TestCase
{
    public function testCompressesWhitespace(): void
    {
        $compressor = new SkillBasedCompressor();
        $original = "hello    world\n\n\n\nfoo";
        $compressed = $compressor->compress($original);

        $this->assertStringNotContainsString("    ", $compressed);
        $this->assertStringNotContainsString("\n\n\n\n", $compressed);
    }

    public function testStripsMarkdown(): void
    {
        $compressor = new SkillBasedCompressor();
        $original = '## 标题\n**加粗内容** 正常文本';
        $compressed = $compressor->compress($original);

        $this->assertStringNotContainsString('##', $compressed);
        $this->assertStringNotContainsString('**', $compressed);
    }

    public function testReplacesSynonyms(): void
    {
        $compressor = new SkillBasedCompressor();
        $original = '请帮我写一个故事';
        $compressed = $compressor->compress($original);

        $this->assertStringNotContainsString('请帮我', $compressed);
        $this->assertStringContainsString('请', $compressed);
    }

    public function testRemovesCourtesy(): void
    {
        $compressor = new SkillBasedCompressor();
        $original = '请回答问题，谢谢！';
        $compressed = $compressor->compress($original);

        $this->assertStringNotContainsString('谢谢', $compressed);
    }

    public function testCustomSkillChain(): void
    {
        $skill = new class implements CompressionSkillInterface {
            public function name(): string
            {
                return 'uppercase';
            }

            public function applicable(array $context = []): bool
            {
                return true;
            }

            public function compress(string $text, array $context = []): string
            {
                return strtoupper($text);
            }
        };

        $compressor = new SkillBasedCompressor([$skill]);
        $this->assertSame('HELLO', $compressor->compress('hello'));
    }

    public function testSavingsReportIncludesSkills(): void
    {
        $compressor = new SkillBasedCompressor();
        $original = '请帮我写一个非常非常非常重要的故事，谢谢';
        $savings = $compressor->savings($original);

        $this->assertArrayHasKey('original', $savings);
        $this->assertArrayHasKey('compressed', $savings);
        $this->assertArrayHasKey('saved', $savings);
        $this->assertArrayHasKey('ratio', $savings);
        $this->assertArrayHasKey('skills', $savings);
        $this->assertNotEmpty($savings['skills']);
        $this->assertGreaterThan(0, $savings['saved']);
    }

    public function testMaxTokensTruncation(): void
    {
        $compressor = new SkillBasedCompressor();
        $longText = str_repeat('这是一段测试文本，用于验证压缩功能是否正常工作。', 50);
        $compressed = $compressor->compress($longText, maxTokens: 100);

        $counter = new \Kode\AiAgent\Token\TokenCounter();
        $compressedTokens = $counter->estimate($compressed);

        $this->assertLessThanOrEqual(120, $compressedTokens);
    }
}
