<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Token\ModelTokenEfficiency;
use Kode\AiAgent\Token\TokenBalancer;
use PHPUnit\Framework\TestCase;

/**
 * Token 消耗平衡器测试
 */
final class TokenBalancerTest extends TestCase
{
    public function testEquivalentTokensNormalizesByModel(): void
    {
        $balancer = new TokenBalancer();

        // Claude 中文效率低，135 tokens 应归一化为约 100
        $claudeEquivalent = $balancer->equivalentTokens('claude-3-5-sonnet', 135, '你好世界');
        $this->assertSame(100, $claudeEquivalent);

        // DeepSeek 中文效率高，85 tokens 应归一化为约 100
        $deepseekEquivalent = $balancer->equivalentTokens('deepseek-chat', 85, '你好世界');
        $this->assertSame(100, $deepseekEquivalent);
    }

    public function testDetectLanguage(): void
    {
        $efficiency = new ModelTokenEfficiency();

        $this->assertSame('chinese', $efficiency->detectLanguage('你好世界，这是中文'));
        $this->assertSame('english', $efficiency->detectLanguage('Hello world, this is English'));
        $this->assertSame('code', $efficiency->detectLanguage('function foo() { return 1; }'));
        $this->assertSame('overall', $efficiency->detectLanguage('1234567890'));
    }

    public function testRecommendMostEfficientPrefersChineseOptimized(): void
    {
        $balancer = new TokenBalancer();
        $best = $balancer->recommendMostEfficient(
            ['claude-3-5-sonnet', 'deepseek-chat', 'qwen-plus'],
            '请帮我写一个中文故事'
        );

        // DeepSeek 和 Qwen 中文效率更高，推荐结果应为其中之一
        $this->assertContains($best, ['deepseek-chat', 'qwen-plus']);
    }

    public function testRecommendCheapest(): void
    {
        $balancer = new TokenBalancer();
        $prices = [
            'gpt-4o' => ['prompt' => 0.0025, 'completion' => 0.01],
            'deepseek-chat' => ['prompt' => 0.00014, 'completion' => 0.00028],
        ];

        $best = $balancer->recommendCheapest(['gpt-4o', 'deepseek-chat'], $prices, '你好');
        $this->assertSame('deepseek-chat', $best);
    }

    public function testReportIsSortedByEquivalentTokens(): void
    {
        $balancer = new TokenBalancer();
        $report = $balancer->report(
            ['claude-3-5-sonnet', 'deepseek-chat'],
            '这是一个中文测试文本'
        );

        $this->assertCount(2, $report);
        $this->assertLessThanOrEqual(
            $report[1]['equivalent_tokens'],
            $report[0]['equivalent_tokens']
        );
    }
}
