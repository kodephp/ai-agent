<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Moe\{Expert, ModelRouter, RoutingContext};
use Kode\AiAgent\Moe\Strategy\CostAwareStrategy;
use Kode\AiAgent\Moe\Strategy\RoundRobinStrategy;
use PHPUnit\Framework\TestCase;

/**
 * 路由策略测试
 */
final class RoutingStrategyTest extends TestCase
{
    public function testRoundRobinRotatesExperts(): void
    {
        $router = new ModelRouter(new RoundRobinStrategy());
        $experts = [
            new Expert(adapter: $this->mockAdapter('a'), priority: 10),
            new Expert(adapter: $this->mockAdapter('b'), priority: 10),
            new Expert(adapter: $this->mockAdapter('c'), priority: 10),
        ];
        foreach ($experts as $expert) {
            $router->registerExpert($expert);
        }

        $context = new RoutingContext();
        $selected1 = $router->select($context);
        $selected2 = $router->select($context);
        $selected3 = $router->select($context);
        $selected4 = $router->select($context);

        $this->assertSame('a', $selected1->platform());
        $this->assertSame('b', $selected2->platform());
        $this->assertSame('c', $selected3->platform());
        $this->assertSame('a', $selected4->platform()); // 循环
    }

    public function testCostAwarePrefersCheaper(): void
    {
        $priceTable = new \Kode\AiAgent\Moe\ModelPriceTable();
        $router = new ModelRouter(new CostAwareStrategy($priceTable));

        // mock adapter 返回真实模型名以触发价格表
        $expensive = $this->createMock(\Kode\AiAgent\Domain\Contract\AdapterInterface::class);
        $expensive->method('name')->willReturn('openai');
        $expensiveAdapterConfig = new \ReflectionObject($expensive);
        // 跳过复杂的反射，用更直接的方式

        $router->registerExpert(new Expert(
            adapter: $this->mockAdapterWithModel('gpt-4o', 'openai'),
            priority: 1,
        ));
        $router->registerExpert(new Expert(
            adapter: $this->mockAdapterWithModel('deepseek-chat', 'deepseek'),
            priority: 100,
        ));

        $context = new RoutingContext();
        $selected = $router->select($context);

        // deepseek 比 gpt-4o 便宜得多，应该选 deepseek
        $this->assertSame('deepseek', $selected->platform());
    }

    private function mockAdapter(string $name): \Kode\AiAgent\Domain\Contract\AdapterInterface
    {
        $adapter = $this->createMock(\Kode\AiAgent\Domain\Contract\AdapterInterface::class);
        $adapter->method('name')->willReturn($name);
        return $adapter;
    }

    private function mockAdapterWithModel(string $model, string $platform): \Kode\AiAgent\Domain\Contract\AdapterInterface
    {
        // 通过创建真实 OpenAiAdapter 但使用无效 URL（不会被实际调用）
        // 简化做法：直接创建 fake mock
        $adapter = new class($platform) implements \Kode\AiAgent\Domain\Contract\AdapterInterface {
            public function __construct(private string $name) {}
            public function name(): string { return $this->name; }
            public function send(\Kode\AiAgent\Domain\Contract\PromptInterface $prompt, array $options = []): \Kode\AiAgent\Domain\Contract\ResponseInterface {
                return new \Kode\AiAgent\Domain\Model\Response(content: 'reply');
            }
            public function stream(\Kode\AiAgent\Domain\Contract\PromptInterface $prompt, array $options = []): \Generator {
                yield 'reply';
            }
            public function config(): array { return ['model' => $this->model ?? 'default']; }
            public ?string $model = null;
        };
        $adapter->model = $model;
        return $adapter;
    }
}
