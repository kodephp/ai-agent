<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Contract\AdapterInterface;
use Kode\AiAgent\Domain\Model\Response;
use Kode\AiAgent\Support\Facade\Ai;
use PHPUnit\Framework\TestCase;

final class AiFacadeTeamTest extends TestCase
{
    protected function setUp(): void
    {
        (new Ai())->reset();
    }

    public function testTeamWithDefaultAdapter(): void
    {
        $ai = new Ai();
        $ai->setDefaultAdapter($this->createAdapter('默认响应'));

        $team = $ai->team();
        $response = $team->dispatch('执行员', '执行任务');

        $this->assertSame('默认响应', $response->content());
    }

    public function testTeamWithRoleAdapterNames(): void
    {
        $ai = new Ai();
        $ai->register('chief', $this->createAdapter('总工响应'));
        $ai->register('analyst', $this->createAdapter('分析响应'));

        $team = $ai->team([
            '总工' => 'chief',
            '分析员' => 'analyst',
        ]);

        $this->assertSame('总工响应', $team->dispatch('总工', '规划架构')->content());
        $this->assertSame('分析响应', $team->dispatch('分析员', '拆解风险')->content());
    }

    private function createAdapter(string $content): AdapterInterface
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('send')->willReturn(new Response(content: $content));
        $adapter->method('stream')->willReturn((function () {
            yield 'chunk';
        })());
        $adapter->method('name')->willReturn('mock');

        return $adapter;
    }
}
