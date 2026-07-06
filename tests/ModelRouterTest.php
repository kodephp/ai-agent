<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\Prompt;
use Kode\AiAgent\Domain\Model\Response;
use Kode\AiAgent\Moe\{Expert, ModelRouter};
use Kode\AiAgent\Moe\RoutingContext;
use Kode\AiAgent\Moe\Strategy\CapabilityAwareStrategy;
use PHPUnit\Framework\TestCase;

/**
 * 模型路由器测试
 */
final class ModelRouterTest extends TestCase
{
    public function testSelectByCapability(): void
    {
        $router = new ModelRouter();
        $router->registerExpert(new Expert(
            adapter: $this->createAdapter('platform-a'),
            capabilities: ['chat'],
            priority: 100,
        ));
        $router->registerExpert(new Expert(
            adapter: $this->createAdapter('platform-b'),
            capabilities: ['code'],
            priority: 10,
        ));

        $context = new RoutingContext(capability: 'code');
        $selected = $router->select($context);

        $this->assertSame('platform-b', $selected->platform());
    }

    public function testSelectByPriority(): void
    {
        $router = new ModelRouter();
        $router->registerExpert(new Expert(
            adapter: $this->createAdapter('low-priority'),
            capabilities: ['chat'],
            priority: 100,
        ));
        $router->registerExpert(new Expert(
            adapter: $this->createAdapter('high-priority'),
            capabilities: ['chat'],
            priority: 10,
        ));

        $context = new RoutingContext(capability: 'chat');
        $selected = $router->select($context);

        $this->assertSame('high-priority', $selected->platform());
    }

    public function testPreferredPlatform(): void
    {
        $router = new ModelRouter();
        $router->registerExpert(new Expert(
            adapter: $this->createAdapter('platform-a'),
            capabilities: ['chat'],
            priority: 10,
        ));
        $router->registerExpert(new Expert(
            adapter: $this->createAdapter('platform-b'),
            capabilities: ['chat'],
            priority: 100,
        ));

        $context = new RoutingContext(
            capability: 'chat',
            preferredPlatform: 'platform-b',
        );
        $selected = $router->select($context);

        $this->assertSame('platform-b', $selected->platform());
    }

    public function testUnhealthyExpertsAreSkipped(): void
    {
        $router = new ModelRouter();
        $expert1 = new Expert(
            adapter: $this->createAdapter('platform-a'),
            capabilities: ['chat'],
            priority: 1, // 优先级最高
        );
        $expert2 = new Expert(
            adapter: $this->createAdapter('platform-b'),
            capabilities: ['chat'],
            priority: 100,
        );

        $expert1->markUnhealthy('test');

        $router->registerExpert($expert1);
        $router->registerExpert($expert2);

        $context = new RoutingContext(capability: 'chat');
        $selected = $router->select($context);

        $this->assertSame('platform-b', $selected->platform());
    }

    public function testNoExpertsThrowsException(): void
    {
        $router = new ModelRouter();

        $this->expectException(\RuntimeException::class);
        $router->select(new RoutingContext());
    }

    public function testDispatchRecordsStatistics(): void
    {
        $response = new Response(
            content: 'reply',
            usage: ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        );

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('name')->willReturn('test');
        $adapter->method('send')->willReturn($response);

        $router = new ModelRouter();
        $router->registerExpert(new Expert(adapter: $adapter));

        $router->dispatch(new Prompt('hi'));

        $stats = $router->statistics();
        $this->assertCount(1, $stats);
        $this->assertSame(150, $stats['test:default']['total_tokens']);
        $this->assertSame(1, $stats['test:default']['success']);
    }

    private function createAdapter(string $name): AdapterInterface
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('name')->willReturn($name);
        return $adapter;
    }
}
