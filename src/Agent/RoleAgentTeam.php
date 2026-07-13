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
    use AgentTeamTrait;

    private array $routes = [];
    private array $hooks = [];
    private array $context = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->initTeamTrait($logger, '执行员');
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
        $role = $this->resolveRoleWithRoute($task);
        return $this->dispatch($role, $task, $options);
    }

    public function run(string $goal, array $workflow, array $options = []): array
    {
        $this->log('info', '工作流开始', [
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

        $this->log('info', '工作流完成', [
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
        $this->log('info', '并行任务开始', ['count' => count($tasks)]);

        $promises = [];
        $startTime = microtime(true);

        foreach ($tasks as $index => $task) {
            $role = is_array($task) ? (string) ($task['role'] ?? 'default') : 'default';
            $message = is_array($task) ? (string) ($task['message'] ?? $task['task'] ?? '') : (string) $task;
            $taskOptions = is_array($task) ? ($task['options'] ?? []) : [];

            if (!$this->has($role)) {
                $role = $this->resolveRole($message);
            }

            $promises[] = [
                'index' => $index,
                'role' => $role,
                'message' => $message,
                'promise' => $this->dispatch($role, $message, $taskOptions),
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

        $this->log('info', '并行任务完成', [
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
        $lastResult = null;

        foreach ($stages as $index => $stage) {
            $result = $stage($context);
            $lastResult = $result;
            $context["stage_{$index}_result"] = $result;

            if ($result instanceof ResponseInterface) {
                $context["stage_{$index}_content"] = $result->content();
            } else {
                $context["stage_{$index}_content"] = $result;
            }
        }

        return $lastResult ?? throw new \RuntimeException('Pipeline produced no result');
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

    private function resolveRoleWithRoute(string $task): string
    {
        $normalizedTask = mb_strtolower($task);

        foreach ($this->routes as $pattern => $role) {
            $keywords = array_filter(array_map('trim', explode('|', $pattern)));
            foreach ($keywords as $keyword) {
                if (str_contains($normalizedTask, $keyword)) {
                    return $role;
                }
            }
        }

        return $this->resolveRole($task);
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
                $this->logger->warning('Hook 执行失败', [
                    'event' => $event,
                    'role' => $role,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
