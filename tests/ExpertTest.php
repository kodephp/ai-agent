<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\Prompt;
use Kode\AiAgent\Domain\Model\Response;
use Kode\AiAgent\Moe\{Expert, MoEGateway, ModelRouter};
use Kode\AiAgent\Moe\Strategy\CapabilityAwareStrategy;
use PHPUnit\Framework\TestCase;

/**
 * 专家（Expert）测试
 */
final class ExpertTest extends TestCase
{
    public function testExpertId(): void
    {
        $adapter = $this->createMockAdapter('openai');
        $expert = new Expert(
            adapter: $adapter,
            capabilities: ['chat'],
        );

        $this->assertSame('openai:default', $expert->id());
        $this->assertSame('openai', $expert->platform());
    }

    public function testCustomId(): void
    {
        $adapter = $this->createMockAdapter('openai');
        $expert = new Expert(
            adapter: $adapter,
            id: 'my-expert',
        );

        $this->assertSame('my-expert', $expert->id());
    }

    public function testDefaultHealthy(): void
    {
        $adapter = $this->createMockAdapter('openai');
        $expert = new Expert(adapter: $adapter);
        $this->assertTrue($expert->isHealthy());
    }

    public function testMarkUnhealthy(): void
    {
        $adapter = $this->createMockAdapter('openai');
        $expert = new Expert(adapter: $adapter);

        $expert->markUnhealthy('test failure');
        $this->assertFalse($expert->isHealthy());
        $this->assertSame('test failure', $expert->unhealthyReason());
    }

    public function testRecoverFromUnhealthy(): void
    {
        $adapter = $this->createMockAdapter('openai');
        $expert = new Expert(
            adapter: $adapter,
            unhealthyTtlSec: 1, // 1 秒后恢复
        );

        $expert->markUnhealthy('test');
        $this->assertFalse($expert->isHealthy());

        sleep(2);
        $this->assertTrue($expert->isHealthy());
    }

    public function testPermanentUnhealthy(): void
    {
        $adapter = $this->createMockAdapter('openai');
        $expert = new Expert(
            adapter: $adapter,
            unhealthyTtlSec: 0, // 永不自愈
        );

        $expert->markUnhealthy('test');
        $this->assertFalse($expert->isHealthy());

        $expert->markHealthy();
        $this->assertTrue($expert->isHealthy());
    }

    public function testSendDelegatesToAdapter(): void
    {
        $expected = new Response(content: 'reply');
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('name')->willReturn('openai');
        $adapter->expects($this->once())
            ->method('send')
            ->willReturn($expected);

        $expert = new Expert(adapter: $adapter);
        $result = $expert->send(new Prompt('hi'));

        $this->assertSame($expected, $result);
    }

    private function createMockAdapter(string $name, ?ResponseInterface $response = null): AdapterInterface
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('name')->willReturn($name);
        return $adapter;
    }
}
