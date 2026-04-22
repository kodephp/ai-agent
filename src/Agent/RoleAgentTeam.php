<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Kode\AiAgent\Domain\Contract\AgentTeamInterface;
use Kode\AiAgent\Domain\Contract\ResponseInterface;
use Kode\AiAgent\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RoleAgentTeam implements AgentTeamInterface
{
    private array $agents = [];
    private array $routes = [];
    private array $hooks = [];
    private ?CostTracker $costTracker = null;
    private LoggerInterface $logger;
    private array $context = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function assign(string $role, Agent $agent): self
    {
        $this->agents[$role] = $agent;
        $this->logger->debug("Agent 角色已分配", ['role' => $role]);
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
        $this->logger->info("Agent 分发开始", [
            'role' => $role,
            'task_length' => strlen($task),
        ]);

        $startTime = microtime(true);

        try {
            $this->fireHook('before', $role, $task, $options);

            $response = $this->agent($role)->chat($task, $options);

            $duration = microtime(true) - $startTime;
            $this->fireHook('after', $role, $task, $response);

            $this->trackCost($role, $response);

            $this->logger->info("Agent 分发完成", [
                'role' => $role,
                'duration' => round($duration, 3),
                'content_length' => strlen($response->content()),
            ]);

            return $response;
        } catch (\Throwable $e) {
            $this->logger->error("Agent 分发失败", [
                'role' => $role,
                'error' => $e->getMessage(),
            ]);
            $this->fireHook('error', $role, $task, $e);
            throw $e;
        }
    }

    public function route(string $pattern, string $role): self
    {
        $normalizedPattern = trim(mb_strtolower($pattern));
        if ($normalizedPattern === '') {
            throw ConfigurationException::missing('route.pattern');
        }

        if (!$this->has($role)) {
            throw ConfigurationException::unsupportedPlatform($role);
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
        $this->logger->info("工作流开始", [
            'goal' => $goal,
            'steps' => count($workflow),
        ]);

        $outputs = [];
        $workflowContext = array_merge($this->context, [
            'goal' => $goal,
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        foreach ($workflow as $index => $step) {
            $role = is_array($step) ? (string) ($step['role'] ?? '') : (string) $step;
            $task = is_array($step) ? (string) ($step['task'] ?? $goal) : $goal;
            $task = str_replace('{{goal}}', $goal, $task);

            if ($role === '') {
                throw ConfigurationException::missing('workflow.role');
            }

            $stepStartTime = microtime(true);

            $response = $this->dispatch($role, $task, $options);

            $stepDuration = microtime(true) - $stepStartTime;

            $output = [
                'step' => $index + 1,
                'role' => $role,
                'task' => $task,
                'content' => $response->content(),
                'response' => $response,
                'duration' => round($stepDuration, 3),
            ];

            $outputs[] = $output;

            $workflowContext["output_{$role}"] = $response->content();
            $workflowContext["result_step_{$index}"] = $output;
        }

        $workflowContext['completed_at'] = date('Y-m-d H:i:s');
        $workflowContext['total_duration'] = array_sum(array_column($outputs, 'duration'));

        $this->logger->info("工作流完成", [
            'goal' => $goal,
            'steps' => count($outputs),
            'total_duration' => round($workflowContext['total_duration'], 3),
        ]);

        return [
            'goal' => $goal,
            'steps' => $outputs,
            'context' => $workflowContext,
        ];
    }

    public function parallel(array $tasks, array $options = []): array
    {
        $this->logger->info("并行任务开始", ['count' => count($tasks)]);

        $promises = [];
        $startTime = microtime(true);

        foreach ($tasks as $index => $task) {
            $role = is_array($task) ? (string) ($task['role'] ?? 'default') : 'default';
            $message = is_array($task) ? (string) ($task['message'] ?? $task['task'] ?? '') : (string) $task;
            $taskOptions = is_array($task) ? ($task['options'] ?? []) : [];

            if (!$this->has($role)) {
                $role = $this->resolveRole($message);
            }

            $promise = $this->dispatchAsync($role, $message, $taskOptions);
            $promises[] = [
                'index' => $index,
                'role' => $role,
                'message' => $message,
                'promise' => $promise,
            ];
        }

        $results = [];
        foreach ($promises as $promise) {
            $results[$promise['index']] = [
                'role' => $promise['role'],
                'content' => $promise['promise']->content(),
                'response' => $promise['promise'],
            ];
        }

        $duration = microtime(true) - $startTime;

        $this->logger->info("并行任务完成", [
            'count' => count($results),
            'duration' => round($duration, 3),
        ]);

        return [
            'results' => $results,
            'duration' => round($duration, 3),
        ];
    }

    public function pipeline(callable ...$stages): ResponseInterface
    {
        $context = [];

        foreach ($stages as $index => $stage) {
            $result = $stage($context);
            $context["stage_{$index}_result"] = $result;

            if ($result instanceof ResponseInterface) {
                $context["stage_{$index}_content"] = $result->content();
            } else {
                $context["stage_{$index}_content"] = $result;
            }
        }

        return $context["stage_{$index}_result"] ?? throw new \RuntimeException('Pipeline produced no result');
    }

    public function on(string $event, callable $hook): self
    {
        $this->hooks[$event][] = $hook;
        return $this;
    }

    public function withCostTracker(CostTracker $tracker): self
    {
        $this->costTracker = $tracker;
        return $this;
    }

    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getCostReport(): array
    {
        if ($this->costTracker === null) {
            return [];
        }
        return $this->costTracker->summary();
    }

    public function reset(): self
    {
        $this->agents = [];
        $this->routes = [];
        $this->hooks = [];
        $this->context = [];
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

    private function fireHook(string $event, string $role, mixed ...$args): void
    {
        if (!isset($this->hooks[$event])) {
            return;
        }

        foreach ($this->hooks[$event] as $hook) {
            try {
                $hook($role, ...$args);
            } catch (\Throwable $e) {
                $this->logger->warning("Hook 执行失败", [
                    'event' => $event,
                    'role' => $role,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function trackCost(string $role, ResponseInterface $response): void
    {
        if ($this->costTracker === null) {
            return;
        }

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

    private function dispatchAsync(string $role, string $task, array $options = []): ResponseInterface
    {
        return $this->dispatch($role, $task, $options);
    }
}
