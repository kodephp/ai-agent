<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Kode\AiAgent\Domain\Contract\ResponseInterface;
use Kode\AiAgent\Exception\ConfigurationException;
use Kode\AiAgent\Exception\PlatformException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 主管 Agent
 *
 * 类似于 GSD-2 的 Supervisor 模式，一个主管 Agent 协调多个专门 Agent 的工作。
 * 支持自动任务分解、路由、执行和结果聚合。
 * 集成 ExecutionContext 实现协程安全的任务执行管理。
 *
 * @package Kode\AiAgent\Agent
 *
 * @example
 * ```php
 * $supervisor = new SupervisorAgent($chiefAdapter);
 * $supervisor->register('analyst', $analystAdapter);
 * $supervisor->register('executor', $executorAdapter);
 *
 * $result = $supervisor->supervise('完成项目架构设计', [
 *     'steps' => [
 *         ['role' => 'analyst', 'task' => '分析技术需求'],
 *         ['role' => 'executor', 'task' => '实现核心代码'],
 *     ],
 * ]);
 * ```
 */
class SupervisorAgent
{
    use AgentTeamTrait;

    private array $roleDescriptions = [];
    private bool $useContextStorage = false;
    private ?Agent $supervisor = null;
    private int $maxHistorySize = 100;

    public function __construct(
        ?Agent $supervisor = null,
        ?LoggerInterface $logger = null,
        int $maxHistorySize = 100,
    ) {
        $this->initTeamTrait($logger, 'executor');
        $this->supervisor = $supervisor;
        $this->maxHistorySize = $maxHistorySize;
    }

    public function register(
        string $role,
        Agent $agent,
        ?string $description = null
    ): self {
        $this->agents[$role] = $agent;
        $this->roleDescriptions[$role] = $description ?? "负责 {$role} 相关任务";
        $this->logger->debug('Worker 已注册', ['role' => $role]);
        return $this;
    }

    public function hasWorker(string $role): bool
    {
        return $this->has($role);
    }

    public function worker(string $role): Agent
    {
        return $this->agent($role);
    }

    public function workers(): array
    {
        return $this->agents;
    }

    public function useContextStorage(bool $use = true): self
    {
        $this->useContextStorage = $use;
        return $this;
    }

    public function setSupervisor(Agent $supervisor): self
    {
        $this->supervisor = $supervisor;
        return $this;
    }

    public function executionHistory(): array
    {
        if ($this->useContextStorage) {
            return ExecutionContext::getHistoryFromContext();
        }
        return ExecutionContext::getHistoryFromContext();
    }

    public function clearHistory(): void
    {
        ExecutionContext::clearHistory();
    }

    public function supervise(string $goal, array $workflow, array $options = []): ResponseInterface
    {
        $options['use_context_storage'] = $this->useContextStorage;

        $context = ExecutionContext::create(
            id: $this->generateId(),
            task: $goal,
            role: 'supervisor',
            options: $options,
        );

        $context->start();
        $this->log('info', '开始监督任务', [
            'goal' => substr($goal, 0, 100),
            'steps' => count($workflow),
            'context_id' => $context->id(),
        ]);

        try {
            $plan = $this->plan($goal, $workflow, $options);
            $context->addArtifact('plan', $plan);

            $results = $this->executePlan($plan, $options);
            $context->complete($results);

            $this->log('info', '监督任务完成', [
                'duration' => $context->duration(),
                'steps_completed' => count($results),
                'context_id' => $context->id(),
            ]);

            $finalResponse = $this->synthesize($goal, $results);
            return $finalResponse;
        } catch (\Throwable $e) {
            $context->fail($e->getMessage());
            $this->log('error', '监督任务失败', [
                'error' => $e->getMessage(),
                'attempts' => $context->attempts(),
                'context_id' => $context->id(),
            ]);
            throw PlatformException::connectionFailed($goal, $e);
        }
    }

    public function parallel(array $tasks, array $options = []): array
    {
        $results = [];
        $maxConcurrency = $options['concurrency'] ?? 3;

        $this->log('info', '开始并行任务', [
            'total_tasks' => count($tasks),
            'concurrency' => $maxConcurrency,
        ]);

        foreach (array_chunk($tasks, $maxConcurrency) as $chunk) {
            foreach ($chunk as $task) {
                $role = $task['role'] ?? 'default';
                $taskText = $task['task'] ?? '';
                $taskOptions = $task['options'] ?? [];

                $results[] = [
                    'role' => $role,
                    'task' => $taskText,
                    'response' => $this->dispatch($role, $taskText, $taskOptions),
                ];
            }
        }

        return $results;
    }

    public function auto(string $task, array $options = []): ResponseInterface
    {
        $options['use_context_storage'] = $this->useContextStorage;

        $context = ExecutionContext::create(
            id: $this->generateId(),
            task: $task,
            role: 'supervisor',
            options: $options,
        );

        $context->start();
        $this->log('info', '开始自动任务分解', [
            'task' => substr($task, 0, 100),
            'context_id' => $context->id(),
        ]);

        try {
            $decomposed = $this->decompose($task, $options);

            if (count($decomposed) === 1) {
                $role = $this->routeByKeyword($task);
                $response = $this->dispatch($role, $task, $options);
                $context->complete(['single_task' => true]);
                return $response;
            }

            $workflow = array_map(fn($step) => [
                'role' => $step['role'],
                'task' => $step['task'],
            ], $decomposed);

            $context->complete();
            return $this->supervise($task, $workflow, $options);
        } catch (\Throwable $e) {
            $context->fail($e->getMessage());
            throw $e;
        }
    }

    public function runWithContext(string $goal, array $workflow, array $options = []): ResponseInterface
    {
        return ExecutionContext::run(function ($context) use ($goal, $workflow, $options) {
            $this->log('info', '在协程安全的执行上下文中运行', [
                'context_id' => $context->id(),
            ]);
            return $this->supervise($goal, $workflow, $options);
        });
    }

    private function plan(string $goal, array $workflow, array $options = []): array
    {
        $planPrompt = $this->buildPlanningPrompt($goal, $workflow);
        $response = $this->supervisor?->chat($planPrompt, $options)
            ?? $this->dispatch('supervisor', $planPrompt, $options);

        return [
            'goal' => $goal,
            'workflow' => $workflow,
            'original_response' => $response->content(),
            'timestamp' => microtime(true),
        ];
    }

    private function executePlan(array $plan, array $options = []): array
    {
        $results = [];
        $workflow = $plan['workflow'];

        foreach ($workflow as $index => $step) {
            $role = is_array($step) ? ($step['role'] ?? 'default') : (string) $step;
            $task = is_array($step) ? ($step['task'] ?? $plan['goal']) : (string) $step;

            $this->log('info', "执行步骤 {$index}", [
                'role' => $role,
                'task' => substr($task, 0, 50),
            ]);

            $response = $this->dispatch($role, $task, $options);

            $results[] = [
                'step' => $index,
                'role' => $role,
                'task' => $task,
                'response' => $response,
                'content' => $response->content(),
            ];
        }

        return $results;
    }

    private function synthesize(string $goal, array $results): ResponseInterface
    {
        $synthesisPrompt = "基于以下执行结果，综合回答原始目标。\n\n原始目标：{$goal}\n\n执行结果：\n";

        foreach ($results as $result) {
            $synthesisPrompt .= "【{$result['role']}】{$result['content']}\n\n";
        }

        return $this->supervisor?->chat($synthesisPrompt)
            ?? $this->dispatch('supervisor', $synthesisPrompt, []);
    }

    private function decompose(string $task, array $options = []): array
    {
        $rolesList = implode(', ', $this->roles());

        $decomposePrompt = "将以下任务分解为多个子任务，并分配给合适的角色。\n\n"
            . "可用角色：{$rolesList}\n\n"
            . "任务：{$task}\n\n"
            . '请按以下 JSON 格式返回：' . "\n"
            . '[{"role": "角色名", "task": "子任务描述"}]';

        $response = $this->supervisor?->chat($decomposePrompt, $options)
            ?? $this->dispatch('supervisor', $decomposePrompt, $options);

        $json = $this->extractJson($response->content());

        if ($json === null) {
            return [['role' => $this->roles()[0] ?? 'default', 'task' => $task]];
        }

        return $json;
    }

    private function routeByKeyword(string $task): string
    {
        $taskLower = mb_strtolower($task);

        $keywordMap = [
            '分析' => 'analyst',
            '调研' => 'analyst',
            '研究' => 'analyst',
            '代码' => 'executor',
            '开发' => 'executor',
            '实现' => 'executor',
            '测试' => 'tester',
            '部署' => 'devops',
            '文档' => 'writer',
            '审核' => 'reviewer',
        ];

        foreach ($keywordMap as $keyword => $role) {
            if (str_contains($taskLower, $keyword) && $this->has($role)) {
                return $role;
            }
        }

        return $this->roles()[0] ?? 'default';
    }

    private function buildPlanningPrompt(string $goal, array $workflow): string
    {
        $rolesDescription = "团队角色：\n";
        foreach ($this->roleDescriptions as $role => $description) {
            $rolesDescription .= "- {$role}：{$description}\n";
        }

        $stepsDescription = "执行步骤：\n";
        foreach ($workflow as $index => $step) {
            $role = is_array($step) ? ($step['role'] ?? 'unknown') : (string) $step;
            $task = is_array($step) ? ($step['task'] ?? $goal) : (string) $step;
            $stepsDescription .= ($index + 1) . ". [{$role}] {$task}\n";
        }

        return <<<PROMPT
作为团队主管，请监督以下任务的执行：

{$rolesDescription}

{$stepsDescription}

原始目标：{$goal}

请协调团队完成上述任务，并在必要时进行调整。
PROMPT;
    }

    private function extractJson(string $text): ?array
    {
        if (preg_match('/\[[\s\S]*\]/', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $json;
            }
        }
        return null;
    }

    private function generateId(): string
    {
        return sprintf(
            'sup-%s-%s',
            date('Ymd-His'),
            bin2hex(random_bytes(4))
        );
    }
}
