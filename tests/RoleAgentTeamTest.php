<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Agent\Agent;
use Kode\AiAgent\Agent\RoleAgentTeam;
use Kode\AiAgent\Domain\Contract\AdapterInterface;
use Kode\AiAgent\Domain\Model\Response;
use Kode\AiAgent\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class RoleAgentTeamTest extends TestCase
{
    public function testDispatchByRole(): void
    {
        $team = new RoleAgentTeam();
        $team->assign('总工', $this->createAgentWithResponse('总工方案'));
        $team->assign('分析员', $this->createAgentWithResponse('分析结论'));

        $response = $team->dispatch('总工', '请给出总体技术路线');
        $this->assertSame('总工方案', $response->content());
        $this->assertTrue($team->has('分析员'));
    }

    public function testRunWorkflow(): void
    {
        $team = new RoleAgentTeam();
        $team->assign('总工', $this->createAgentWithResponse('系统设计完成'));
        $team->assign('分析员', $this->createAgentWithResponse('需求拆解完成'));
        $team->assign('执行员', $this->createAgentWithResponse('开发任务已执行'));

        $result = $team->run('构建多模型代理编排', [
            ['role' => '总工', 'task' => '根据目标制定技术方案：{{goal}}'],
            ['role' => '分析员', 'task' => '对方案进行风险与任务拆解'],
            ['role' => '执行员', 'task' => '按照拆解结果执行并输出结果'],
        ]);

        $this->assertSame('构建多模型代理编排', $result['goal']);
        $this->assertCount(3, $result['steps']);
        $this->assertSame('总工', $result['steps'][0]['role']);
        $this->assertSame('系统设计完成', $result['steps'][0]['content']);
    }

    public function testDispatchThrowsForUnknownRole(): void
    {
        $team = new RoleAgentTeam();
        $this->expectException(ConfigurationException::class);
        $team->dispatch('不存在角色', 'task');
    }

    public function testAutoRouteByPattern(): void
    {
        $team = new RoleAgentTeam();
        $team->assign('总工', $this->createAgentWithResponse('总工输出'));
        $team->assign('分析员', $this->createAgentWithResponse('分析输出'));
        $team->assign('执行员', $this->createAgentWithResponse('执行输出'));
        $team->routes([
            '架构|设计|方案' => '总工',
            '分析|风险|拆解' => '分析员',
            '开发|实现|修复' => '执行员',
        ]);

        $design = $team->auto('请给出整体架构设计');
        $analysis = $team->auto('请先分析风险点');
        $execution = $team->auto('请执行修复任务');

        $this->assertSame('总工输出', $design->content());
        $this->assertSame('分析输出', $analysis->content());
        $this->assertSame('执行输出', $execution->content());
    }

    public function testAutoRouteFallsBackToExecutor(): void
    {
        $team = new RoleAgentTeam();
        $team->assign('执行员', $this->createAgentWithResponse('默认执行'));
        $team->assign('总工', $this->createAgentWithResponse('总工输出'));

        $response = $team->auto('一个普通任务');
        $this->assertSame('默认执行', $response->content());
    }

    private function createAgentWithResponse(string $content): Agent
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('send')->willReturn(new Response(content: $content));
        $adapter->method('stream')->willReturn((function () {
            yield 'chunk';
        })());
        $adapter->method('name')->willReturn('mock');

        return new Agent($adapter);
    }
}
