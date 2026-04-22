<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

use Kode\AiAgent\Agent\Agent;

/**
 * Agent 团队接口
 *
 * 定义多 Agent 协作的统一接口，支持工作流编排、任务分发、结果聚合。
 *
 * @package Kode\AiAgent\Domain\Contract
 */
interface AgentTeamInterface
{
    /**
     * 分配角色 Agent
     */
    public function assign(string $role, Agent $agent): self;

    /**
     * 检查是否有指定角色
     */
    public function has(string $role): bool;

    /**
     * 获取所有角色列表
     */
    public function roles(): array;

    /**
     * 获取指定角色 Agent
     */
    public function agent(string $role): Agent;

    /**
     * 分发任务到指定角色
     */
    public function dispatch(string $role, string $task, array $options = []): ResponseInterface;

    /**
     * 自动路由任务到合适的角色
     */
    public function auto(string $task, array $options = []): ResponseInterface;

    /**
     * 执行工作流
     *
     * @return array{goal: string, steps: array, context: array}
     */
    public function run(string $goal, array $workflow, array $options = []): array;

    /**
     * 并行执行多个任务
     *
     * @return array{results: array, duration: float}
     */
    public function parallel(array $tasks, array $options = []): array;
}
