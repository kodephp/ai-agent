<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Moe\MoEGateway;
use Kode\AiAgent\Support\Builder\MoEBuilder;
use PHPUnit\Framework\TestCase;

/**
 * MOE 网关测试
 */
final class MoEGatewayTest extends TestCase
{
    public function testBuilderCreation(): void
    {
        $gateway = MoEBuilder::create()
            ->strategy('capability_aware')
            ->budget(perMinuteTokens: 1000, perDayTokens: 100000)
            ->build();

        $this->assertInstanceOf(MoEGateway::class, $gateway);
        $this->assertSame([], $gateway->experts());
    }

    public function testAddExpertWithMockAdapter(): void
    {
        $adapter = $this->createMock(\Kode\AiAgent\Domain\Contract\AdapterInterface::class);
        $adapter->method('name')->willReturn('mock');

        $gateway = MoEBuilder::create()->build();
        $gateway->addAdapter($adapter, ['chat'], priority: 10);

        $this->assertCount(1, $gateway->experts());
    }

    public function testReport(): void
    {
        $adapter = $this->createMock(\Kode\AiAgent\Domain\Contract\AdapterInterface::class);
        $adapter->method('name')->willReturn('mock');

        $gateway = MoEBuilder::create()
            ->budget(perMinuteTokens: 1000, perDayTokens: 100000)
            ->build();
        $gateway->addAdapter($adapter, ['chat'], priority: 10);

        $report = $gateway->report();

        $this->assertArrayHasKey('experts', $report);
        $this->assertArrayHasKey('totals', $report);
        $this->assertArrayHasKey('budget', $report);
        $this->assertCount(1, $report['experts']);
    }

    public function testPriceTableAccessible(): void
    {
        $gateway = MoEBuilder::create()->build();
        $this->assertSame(0.0025, $gateway->priceTable()->promptPrice('gpt-4o'));
    }
}
