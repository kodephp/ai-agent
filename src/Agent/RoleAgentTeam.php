<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Kode\AiAgent\Domain\Contract\ResponseInterface;
use Kode\AiAgent\Exception\ConfigurationException;

final class RoleAgentTeam
{
    private array $agents = [];
    private array $routes = [];

    public function assign(string $role, Agent $agent): self
    {
        $this->agents[$role] = $agent;
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

    public function dispatch(string $role, string $task, array $options = []): ResponseInterface
    {
        return $this->agent($role)->chat($task, $options);
    }

    public function route(string $pattern, string $role): self
    {
        $normalizedPattern = trim(mb_strtolower($pattern));
        if ($normalizedPattern === '') {
            throw ConfigurationException::missing('route.pattern');
        }

        $this->routes[$normalizedPattern] = $role;
        return $this;
    }

    public function routes(array $mapping): self
    {
        foreach ($mapping as $pattern => $role) {
            $this->route((string) $pattern, (string) $role);
        }
        return $this;
    }

    public function auto(string $task, array $options = []): ResponseInterface
    {
        $role = $this->resolveRole($task);
        return $this->dispatch($role, $task, $options);
    }

    public function run(string $goal, array $workflow, array $options = []): array
    {
        $outputs = [];

        foreach ($workflow as $step) {
            $role = is_array($step) ? (string) ($step['role'] ?? '') : (string) $step;
            $task = is_array($step) ? (string) ($step['task'] ?? $goal) : $goal;
            $task = str_replace('{{goal}}', $goal, $task);

            if ($role === '') {
                throw ConfigurationException::missing('workflow.role');
            }

            $response = $this->dispatch($role, $task, $options);
            $outputs[] = [
                'role' => $role,
                'task' => $task,
                'content' => $response->content(),
                'response' => $response,
            ];
        }

        return [
            'goal' => $goal,
            'outputs' => $outputs,
        ];
    }

    public function reset(): self
    {
        $this->agents = [];
        $this->routes = [];
        return $this;
    }

    private function resolveRole(string $task): string
    {
        $normalizedTask = mb_strtolower($task);

        foreach ($this->routes as $pattern => $role) {
            $keywords = array_filter(array_map('trim', explode('|', $pattern)));
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($normalizedTask, $keyword)) {
                    return $role;
                }
            }
        }

        if ($this->has('执行员')) {
            return '执行员';
        }

        $roles = $this->roles();
        if (empty($roles)) {
            throw ConfigurationException::missing('team.roles');
        }

        return $roles[0];
    }
}
