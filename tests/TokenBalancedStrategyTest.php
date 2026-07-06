<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Contract\AdapterInterface;
use Kode\AiAgent\Domain\Contract\PromptInterface;
use Kode\AiAgent\Domain\Contract\ResponseInterface;
use Kode\AiAgent\Domain\Model\Response;
use Kode\AiAgent\Moe\Expert;
use Kode\AiAgent\Moe\ModelRouter;
use Kode\AiAgent\Moe\RoutingContext;
use Kode\AiAgent\Moe\Strategy\TokenBalancedStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Token 均衡路由策略测试
 */
final class TokenBalancedStrategyTest extends TestCase
{
    public function testPrefersChineseEfficientModel(): void
    {
        $router = new ModelRouter(new TokenBalancedStrategy());

        $router->registerExpert(new Expert(
            adapter: $this->mockAdapter('claude-3-5-sonnet', 'anthropic'),
            capabilities: ['chat'],
            priority: 1,
        ));
        $router->registerExpert(new Expert(
            adapter: $this->mockAdapter('deepseek-chat', 'deepseek'),
            capabilities: ['chat'],
            priority: 100,
        ));

        $context = new RoutingContext(
            capability: 'chat',
            promptText: '请帮我写一个中文故事',
        );
        $selected = $router->select($context);

        // DeepSeek 中文效率更高且更便宜
        $this->assertSame('deepseek', $selected->platform());
    }

    public function testFallbackWhenNoCapabilityMatch(): void
    {
        $router = new ModelRouter(new TokenBalancedStrategy());

        $router->registerExpert(new Expert(
            adapter: $this->mockAdapter('gpt-4o', 'openai'),
            capabilities: ['chat'],
            priority: 1,
        ));

        $context = new RoutingContext(
            capability: 'code',
            promptText: 'hello',
        );
        $selected = $router->select($context);

        $this->assertSame('openai', $selected->platform());
    }

    public function testHonorsPreferredModel(): void
    {
        $router = new ModelRouter(new TokenBalancedStrategy());

        $router->registerExpert(new Expert(
            adapter: $this->mockAdapter('gpt-4o', 'openai'),
            capabilities: ['chat'],
        ));
        $router->registerExpert(new Expert(
            adapter: $this->mockAdapter('deepseek-chat', 'deepseek'),
            capabilities: ['chat'],
        ));

        $context = new RoutingContext(
            capability: 'chat',
            preferredModel: 'gpt-4o',
            promptText: '中文文本',
        );
        $selected = $router->select($context);

        $this->assertSame('openai', $selected->platform());
    }

    private function mockAdapter(string $model, string $platform): AdapterInterface
    {
        return new class($model, $platform) implements AdapterInterface {
            public function __construct(
                private string $model,
                private string $platform,
            ) {}

            public function name(): string
            {
                return $this->platform;
            }

            public function send(PromptInterface $prompt, array $options = []): ResponseInterface
            {
                return new Response(content: 'reply');
            }

            public function stream(PromptInterface $prompt, array $options = []): \Generator
            {
                yield 'reply';
            }

            public function config(): array
            {
                return ['model' => $this->model];
            }
        };
    }
}
