<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Kode\AiAgent\Domain\Contract\ResponseInterface;
use Kode\AiAgent\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Agent 团队共享 Trait
 *
 * 提供多 Agent 协作的公共功能，被 SupervisorAgent 和 RoleAgentTeam 共用。
 *
 * @package Kode\AiAgent\Agent
 */
trait AgentTeamTrait
{
    /** @var array<string, Agent> */
    private array $agents = [];

    private CostTracker $costTracker;
    private LoggerInterface $logger;
    private string $defaultRole = 'default';

    protected function initTeamTrait(?LoggerInterface $logger = null, string $defaultRole = 'default'): void
    {
        $this->costTracker = new CostTracker();
        $this->logger = $logger ?? new NullLogger();
        $this->defaultRole = $defaultRole;
    }

    protected function setDefaultRole(string $role): void
    {
        $this->defaultRole = $role;
    }

    public function assign(string $role, Agent $agent): self
    {
        $this->agents[$role] = $agent;
        $this->logger->debug('Agent 角色已分配', ['role' => $role]);
        return $this;
    }

    public function has(string $role): bool
    {
        return isset($this->agents[$role]);
    }

    public function roles(): array
    {
        return array_keys($this->agents);
    }

    public function agent(string $role): Agent
    {
        return $this->agents[$role] ?? throw ConfigurationException::unsupportedPlatform($role);
    }

    public function costTracker(): CostTracker
    {
        return $this->costTracker;
    }

    public function dispatch(string $role, string $task, array $options = []): ResponseInterface
    {
        $this->logger->info("Agent 分发", [
            'role' => $role,
            'task_length' => strlen($task),
        ]);

        $startTime = microtime(true);
        $response = $this->agent($role)->chat($task, $options);
        $duration = microtime(true) - $startTime;

        $this->trackCost($role, $response);

        $this->logger->info("Agent 分发完成", [
            'role' => $role,
            'duration' => round($duration, 3),
        ]);

        return $response;
    }

    protected function trackCost(string $role, ResponseInterface $response): void
    {
        $usage = $response->usage();
        if (!empty($usage)) {
            $this->costTracker->track(
                $role,
                $usage['prompt_tokens'] ?? 0,
                $usage['completion_tokens'] ?? 0,
                $usage['total_tokens'] ?? 0
            );
        }
    }

    protected function resolveRole(string $task): string
    {
        $taskLower = mb_strtolower($task);

        foreach ($this->agents as $role => $agent) {
            if (str_contains($taskLower, mb_strtolower($role))) {
                return $role;
            }
        }

        if ($this->has($this->defaultRole)) {
            return $this->defaultRole;
        }

        $roles = $this->roles();
        if (empty($roles)) {
            throw ConfigurationException::missing('team.roles');
        }

        return $roles[0];
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        $this->logger->$level("[Team] {$message}", $context);
    }
}
