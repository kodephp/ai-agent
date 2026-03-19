<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Kode\AiAgent\Domain\Contract\ResponseInterface;
use Kode\AiAgent\Exception\ConfigurationException;
use Kode\AiAgent\Exception\PlatformException;
use Psr\Log\LoggerInterface;

/**
 * 主管 Agent
 *
 * 类似于 GSD-2 的 Supervisor 模式，一个主管 Agent 协调多个专门 Agent 的工作。
 * 支持自动任务分解、路由、执行和结果聚合。
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
 *     'task' => '分析需求并设计架构',
 *     'steps' => [
 *         ['role' => 'analyst', 'task' => '分析技术需求'],
 *         ['role' => 'executor', 'task' => '实现核心代码'],
 *     ],
 * ]);
 * ```
 */
final class SupervisorAgent
{
    private array $workers = [];
    private array $roleDescriptions = [];
    private CostTracker $costTracker;
    private array $executionHistory = [];

    public function __construct(
        private Agent $supervisor,
        private ?LoggerInterface $logger = null,
        private int $maxHistorySize = 100,
    ) {
        $this->costTracker = new CostTracker();
    }

    public function register(
        string $role,
        Agent $agent,
        ?string $description = null
    ): self {
        $this->workers[$role] = $agent;
        $this->roleDescriptions[$role] = $description ?? "负责 {$role} 相关任务";
        return $this;
    }

    public function hasWorker(string $role): bool
    {
        return isset($this->workers[$role]);
    }

    public function worker(string $role): Agent
    {
        return $this->workers[$role]
            ?? throw ConfigurationException::unsupportedPlatform($role);
    }

    public function workers(): array
    {
        return $this->workers;
    }

    public function roles(): array
    {
        return array_keys($this->workers);
    }

    public function costTracker(): CostTracker
    {
        return $this->costTracker;
    }

    public function executionHistory(): array
    {
        return $this->executionHistory;
    }

    /**
     * 主管监督执行多步骤任务
     */
    public function supervise(string $goal, array $workflow, array $options = []): ResponseInterface
    {
        $context = new ExecutionContext(
            id: $this->generateId(),
            task: $goal,
            role: 'supervisor',
            options: $options,
        );

        $context->start();
        $this->log('info', '开始监督任务', [
            'goal' => substr($goal, 0, 100),
            'steps' => count($workflow),
        ]);

        try {
            $plan = $this->plan($goal, $workflow, $options);
            $context->addArtifact('plan', $plan);

            $results = $this->executePlan($plan, $options);
            $context->complete($results);

            $this->executionHistory[] = $context->toArray();
            $this->trimHistory();

            $finalResponse = $this->synthesize($goal, $results);
            $this->log('info', '监督任务完成', [
                'duration' => $context->duration(),
                'steps_completed' => count($results),
            ]);

            return $finalResponse;
        } catch (\Throwable $e) {
            $context->fail($e->getMessage());
            $this->executionHistory[] = $context->toArray();
            $this->trimHistory();

            $this->log('error', '监督任务失败', [
                'error' => $e->getMessage(),
                'attempts' => $context->attempts(),
            ]);

            throw PlatformException::connectionFailed($goal, $e);
        }
    }

    /**
     * 并行执行多个任务
     */
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

    /**
     * 自动任务分解和执行
     */
    public function auto(string $task, array $options = []): ResponseInterface
    {
        $context = new ExecutionContext(
            id: $this->generateId(),
            task: $task,
            role: 'supervisor',
            options: $options,
        );

        $context->start();

        try {
            $decomposed = $this->decompose($task, $options);

            if (count($decomposed) === 1) {
                $role = $this->route($task);
                $response = $this->dispatch($role, $task, $options);
                $context->complete(['single_task' => true]);
                return $response;
            }

            $workflow = array_map(function ($step) {
                return ['role' => $step['role'], 'task' => $step['task']];
            }, $decomposed);

            $context->complete();
            return $this->supervise($task, $workflow, $options);
        } catch (\Throwable $e) {
            $context->fail($e->getMessage());
            throw $e;
        }
    }

    /**
     * 规划任务执行流程
     */
    private function plan(string $goal, array $workflow, array $options = []): array
    {
        $planPrompt = $this->buildPlanningPrompt($goal, $workflow);
        $response = $this->supervisor->chat($planPrompt, $options);

        return [
            'goal' => $goal,
            'workflow' => $workflow,
            'original_response' => $response->content(),
            'timestamp' => microtime(true),
        ];
    }

    /**
     * 执行计划
     */
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

    /**
     * 综合结果
     */
    private function synthesize(string $goal, array $results): ResponseInterface
    {
        $synthesisPrompt = "基于以下执行结果，综合回答原始目标。\n\n原始目标：{$goal}\n\n执行结果：\n";

        foreach ($results as $result) {
            $synthesisPrompt .= "【{$result['role']}】{$result['content']}\n\n";
        }

        return $this->supervisor->chat($synthesisPrompt);
    }

    /**
     * 自动任务分解
     */
    private function decompose(string $task, array $options = []): array
    {
        $rolesList = implode(', ', $this->roles());

        $decomposePrompt = "将以下任务分解为多个子任务，并分配给合适的角色。\n\n"
            . "可用角色：{$rolesList}\n\n"
            . "任务：{$task}\n\n"
            . "请按以下 JSON 格式返回：\n"
            . '[{"role": "角色名", "task": "子任务描述"}]';

        $response = $this->supervisor->chat($decomposePrompt, $options);

        $json = $this->extractJson($response->content());

        if ($json === null) {
            return [['role' => $this->roles()[0] ?? 'default', 'task' => $task]];
        }

        return $json;
    }

    /**
     * 路由任务到合适的 Agent
     */
    private function route(string $task): string
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
            if (str_contains($taskLower, $keyword) && $this->hasWorker($role)) {
                return $role;
            }
        }

        return $this->roles()[0] ?? 'default';
    }

    /**
     * 分发任务到指定角色
     */
    private function dispatch(string $role, string $task, array $options = []): ResponseInterface
    {
        $agent = $this->worker($role);
        $response = $agent->chat($task, $options);

        if (isset($options['track_cost']) && $options['track_cost']) {
            $usage = $response->usage();
            $model = $options['model'] ?? 'default';

            $this->costTracker->track(
                model: $model,
                promptTokens: $usage['prompt_tokens'] ?? 0,
                completionTokens: $usage['completion_tokens'] ?? 0,
                metadata: ['role' => $role, 'task' => substr($task, 0, 50)],
            );
        }

        return $response;
    }

    /**
     * 构建规划提示词
     */
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

    /**
     * 从文本中提取 JSON
     */
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

    /**
     * 生成唯一 ID
     */
    private function generateId(): string
    {
        return sprintf(
            'sup-%s-%s',
            date('Ymd-His'),
            bin2hex(random_bytes(4))
        );
    }

    /**
     * 裁剪历史记录
     */
    private function trimHistory(): void
    {
        if (count($this->executionHistory) > $this->maxHistorySize) {
            $this->executionHistory = array_slice(
                $this->executionHistory,
                -$this->maxHistorySize
            );
        }
    }

    /**
     * 日志记录
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->$level("[Supervisor] {$message}", $context);
        }
    }
}
