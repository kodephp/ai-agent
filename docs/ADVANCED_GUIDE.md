# Kode AI Agent 高级功能开发指南

本指南详细介绍 kode/ai-agent 的高级功能，包括分工代理、主管 Agent、成本追踪、协程安全等特性。

## 目录

1. [主管 Agent（SupervisorAgent）](#1-主管-agentsupervisoragent)
2. [执行上下文（ExecutionContext）](#2-执行上下文-executioncontext)
3. [成本追踪器（CostTracker）](#3-成本追踪器-costtracker)
4. [协程安全编程](#4-协程安全编程)
5. [完整使用案例](#5-完整使用案例)
6. [最佳实践](#6-最佳实践)

---

## 1. 主管 Agent（SupervisorAgent）

SupervisorAgent 是分工代理的高级实现，类似于 GSD-2 的 Supervisor 模式。一个主管 Agent 协调多个专门 Agent 的工作。

### 1.1 基本概念

SupervisorAgent 提供以下核心功能：
- **自动任务分解**：将复杂任务拆分为子任务
- **智能路由**：基于关键词自动路由到合适的 Agent
- **并行执行**：支持多任务并行处理
- **结果聚合**：综合多个 Agent 的输出
- **成本追踪**：集成 CostTracker 监控 Token 使用
- **协程安全**：支持在 Swoole 等协程环境中运行

### 1.2 快速开始

```php
use Kode\AiAgent\Agent\Agent;
use Kode\AiAgent\Agent\SupervisorAgent;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

// 创建主管 Agent（负责协调和综合）
$supervisor = new SupervisorAgent(
    AdapterFactory::openai('sk-chief-xxx', ['model' => 'gpt-4o'])
);

// 注册专门的 Agent
$supervisor->register('analyst',
    AdapterFactory::deepseek('sk-analyst-xxx', ['model' => 'deepseek-chat']),
    '负责技术分析和调研'
)->register('executor',
    AdapterFactory::anthropic('sk-exec-xxx', ['model' => 'claude-3-5-sonnet']),
    '负责代码实现'
)->register('reviewer',
    AdapterFactory::openai('sk-reviewer-xxx', ['model' => 'gpt-4o']),
    '负责代码审核'
);

// 监督执行工作流
$result = $supervisor->supervise('完成项目架构设计', [
    ['role' => 'analyst', 'task' => '分析技术需求和可行性'],
    ['role' => 'executor', 'task' => '实现核心代码架构'],
    ['role' => 'reviewer', 'task' => '审核代码质量'],
]);

echo $result->content();
```

### 1.3 监督执行（supervise）

`supervise()` 方法用于执行预定义的多步骤工作流：

```php
$result = $supervisor->supervise($goal, $workflow, $options);
```

**参数说明：**
- `$goal` (string): 最终目标
- `$workflow` (array): 工作流步骤数组
- `$options` (array): 可选配置
  - `track_cost`: 是否追踪成本
  - `model`: 使用的模型名称
  - `timeout`: 超时时间（秒）
  - `use_context_storage`: 是否使用协程安全存储

**返回值：** ResponseInterface

```php
// 示例：完整工作流
$result = $supervisor->supervise('开发一个博客系统', [
    ['role' => 'analyst', 'task' => '分析技术栈选择'],
    ['role' => 'analyst', 'task' => '分析数据库设计'],
    ['role' => 'executor', 'task' => '实现后端 API'],
    ['role' => 'executor', 'task' => '实现前端页面'],
    ['role' => 'reviewer', 'task' => '代码审查'],
], [
    'track_cost' => true,
    'model' => 'gpt-4o',
]);
```

### 1.4 自动任务分解（auto）

`auto()` 方法自动将复杂任务分解为子任务：

```php
$result = $supervisor->auto('请完成一个用户认证系统的开发');
```

**内部流程：**
1. 主管分析任务内容
2. 自动拆分为多个子任务
3. 根据关键词路由到合适的 Agent
4. 并行或顺序执行子任务
5. 聚合结果并返回

```php
// 自动分解示例
$result = $supervisor->auto('开发一个电商网站');

// 可能被分解为：
// 1. [architect] 设计系统架构
// 2. [backend] 实现商品管理 API
// 3. [backend] 实现订单管理 API
// 4. [frontend] 实现商品展示页面
// 5. [frontend] 实现购物车页面
// 6. [reviewer] 代码审查
```

### 1.5 并行执行（parallel）

`parallel()` 方法并行执行多个独立任务：

```php
$results = $supervisor->parallel($tasks, $options);
```

**参数说明：**
- `$tasks` (array): 任务数组，每个任务包含 `role`, `task`, `options`
- `$options` (array): 可选配置
  - `concurrency`: 最大并发数（默认 3）

```php
// 并行执行多个分析任务
$results = $supervisor->parallel([
    ['role' => 'analyst', 'task' => '分析性能需求'],
    ['role' => 'analyst', 'task' => '分析安全需求'],
    ['role' => 'analyst', 'task' => '分析用户体验需求'],
    ['role' => 'analyst', 'task' => '分析可扩展性需求'],
], ['concurrency' => 2]);  // 每次最多2个并发

// 处理结果
foreach ($results as $result) {
    echo "[{$result['role']}] {$result['response']->content()}\n";
}
```

### 1.6 协程安全执行（runWithContext）

在 Swoole/OpenSwoole 等协程环境中使用：

```php
$result = $supervisor->runWithContext($goal, $workflow, $options);
```

```php
// Swoole 协程环境示例
Co\run(function() use ($supervisor) {
    // 启用协程安全模式
    $supervisor->useContextStorage(true);

    // 在新的协程上下文中运行
    $result = $supervisor->runWithContext('完成项目开发', [
        ['role' => 'analyst', 'task' => '分析需求'],
        ['role' => 'executor', 'task' => '实现代码'],
    ]);

    // 每个协程的执行历史自动隔离
    $history = $supervisor->executionHistory();
});
```

---

## 2. 执行上下文（ExecutionContext）

ExecutionContext 管理单个任务的生命周期。

### 2.1 基本概念

ExecutionContext 提供：
- **状态管理**：pending → running → completed/failed/timeout
- **超时控制**：防止任务长时间挂起
- **重试机制**：支持自动重试
- **耗时统计**：精确的任务执行时间
- **错误记录**：完整的错误堆栈
- **产物追踪**：保存任务产生的中间结果

### 2.2 基本使用

```php
use Kode\AiAgent\Agent\ExecutionContext;

// 创建执行上下文
$context = new ExecutionContext(
    id: 'task-001',
    task: '实现用户认证功能',
    role: 'executor',
    options: [
        'max_attempts' => 3,           // 最大重试次数
        'timeout' => 60,               // 超时时间（秒）
        'use_context_storage' => true, // 启用协程安全存储
        'metadata' => ['user_id' => 123], // 自定义元数据
    ]
);

// 开始执行
$context->start();

// 执行业务逻辑...

if ($success) {
    // 任务成功完成
    $context->complete(['result' => $data, 'output' => 'files']);
} else {
    // 任务失败
    $context->fail('错误原因：' . $error->getMessage());
}
```

### 2.3 状态检查

```php
// 检查状态
if ($context->status() === ExecutionContext::STATUS_RUNNING) {
    echo "任务执行中...\n";
}

// 检查是否超时
if ($context->isTimeout()) {
    echo "任务执行超时\n";
}

// 检查是否终端状态
if ($context->isTerminal()) {
    echo "任务已结束，状态: {$context->status()}\n";
}

// 检查是否可以重试
if ($context->canRetry()) {
    echo "可以重试，剩余次数: " . ($context->maxAttempts() - $context->attempts());
}
```

### 2.4 静态工厂方法

```php
// 创建支持协程存储的上下文
$context = ExecutionContext::create(
    id: 'task-002',
    task: '异步任务',
    role: 'worker',
    options: [
        'timeout' => 120,
        'use_context_storage' => true,
    ]
);

// 在新的协程上下文中执行
$result = ExecutionContext::run(function ($context) {
    $context->start();

    // 执行业务逻辑
    $result = someAsyncOperation();

    $context->complete(['result' => $result]);

    return $result;
});
```

### 2.5 协程安全的执行历史

```php
// 获取所有执行历史
$history = ExecutionContext::getHistoryFromContext();

// 清除历史
ExecutionContext::clearHistory();

// 查看历史条目
foreach ($history as $entry) {
    echo "任务: {$entry['task']}\n";
    echo "状态: {$entry['status']}\n";
    echo "耗时: {$entry['duration']}秒\n";
    echo "尝试次数: {$entry['attempts']}\n";
}
```

---

## 3. 成本追踪器（CostTracker）

CostTracker 监控 Agent 的 Token 使用量和成本。

### 3.1 基本使用

```php
use Kode\AiAgent\Agent\CostTracker;

// 创建追踪器
$tracker = new CostTracker(currency: 'USD', enabled: true);

// 追踪请求
$tracker->track(
    model: 'gpt-4o',
    promptTokens: 100,
    completionTokens: 200,
    metadata: ['user_id' => '123', 'task' => 'chat']
);

// 再次追踪
$tracker->track(
    model: 'claude-3-5-sonnet',
    promptTokens: 150,
    completionTokens: 300,
    metadata: ['role' => 'analyst']
);
```

### 3.2 成本汇总

```php
// 查看汇总统计
$summary = $tracker->summary();

echo "总 Token: {$summary['total_tokens']}\n";
echo "总成本: {$summary['total_cost']}\n";
echo "Prompt Token: {$summary['prompt_tokens']}\n";
echo "Completion Token: {$summary['completion_tokens']}\n";
echo "请求数: {$summary['request_count']}\n";
echo "平均 Token/请求: {$summary['avg_tokens_per_request']}\n";
echo "平均成本/请求: {$summary['avg_cost_per_request']}\n";
```

### 3.3 格式化输出

```php
// 格式化成本（自动选择单位）
echo $tracker->formattedCost();   // "0.003750 USD"

// 格式化 Token 数量
echo $tracker->formattedTokens();  // "750" 或 "1.5K" 或 "2.3M"
```

### 3.4 自定义模型价格

```php
use Kode\AiAgent\Agent\CostTracker;

$tracker = new CostTracker();

// 设置自定义模型价格（每 token 的价格，以美元计）
// OpenAI GPT-4o (默认)
$tracker->setModelPrice('gpt-4o', 0.0000025, 0.00001);

// Anthropic Claude
$tracker->setModelPrice('claude-3-5-sonnet', 0.000003, 0.000015);

// DeepSeek
$tracker->setModelPrice('deepseek-chat', 0.00000014, 0.00000028);

// 自定义模型
$tracker->setModelPrice('my-model', 0.000001, 0.000004);
```

### 3.5 在 SupervisorAgent 中使用

```php
use Kode\AiAgent\Agent\SupervisorAgent;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

$supervisor = new SupervisorAgent(
    AdapterFactory::openai('sk-xxx', ['model' => 'gpt-4o'])
);

// 注册 Agent
$supervisor->register('analyst', AdapterFactory::deepseek('sk-xxx'));
$supervisor->register('executor', AdapterFactory::anthropic('sk-xxx'));

// 执行任务并追踪成本
$result = $supervisor->supervise('完成项目开发', [
    ['role' => 'analyst', 'task' => '分析需求'],
    ['role' => 'executor', 'task' => '实现代码'],
], ['track_cost' => true, 'model' => 'gpt-4o']);

// 获取成本统计
$costSummary = $supervisor->costTracker()->summary();
echo "总成本: {$costSummary['total_cost']}\n";

// 获取成本追踪器进行更多操作
$tracker = $supervisor->costTracker();
$tracker->setModelPrice('custom-model', 0.000001, 0.000003);
```

---

## 4. 协程安全编程

kode/ai-agent 通过集成 kode/context 实现协程安全的上下文管理。

### 4.1 协程安全问题

在传统的 PHP-FPM 模式下，每个请求都是独立的环境，不存在状态共享问题。但在 Swoole、OpenSwoole 等协程环境中，同一个进程可能同时处理多个请求，如果共享状态没有正确隔离，会导致数据混乱。

### 4.2 kode/context 集成

kode/ai-agent 使用 kode/context 实现：
- **线程本地存储**：每个协程/线程有独立的数据副本
- **自动隔离**：不同请求的执行历史、成本等自动隔离
- **无缝集成**：通过选项开关启用

### 4.3 启用协程安全模式

```php
use Kode\AiAgent\Agent\SupervisorAgent;
use Kode\AiAgent\Agent\ExecutionContext;

// 在 SupervisorAgent 中启用
$supervisor->useContextStorage(true);

// 或者在选项中指定
$result = $supervisor->supervise($goal, $workflow, [
    'use_context_storage' => true,
]);
```

### 4.4 Swoole 协程环境示例

```php
use Swoole\Coroutine as Co;
use Kode\AiAgent\Agent\SupervisorAgent;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

// 创建 Supervisor
$supervisor = new SupervisorAgent(
    AdapterFactory::openai('sk-xxx', ['model' => 'gpt-4o'])
);
$supervisor->register('analyst', AdapterFactory::deepseek('sk-xxx'));
$supervisor->register('executor', AdapterFactory::anthropic('sk-xxx'));

// 在协程中运行
Co\run(function() use ($supervisor) {
    // 为当前协程启用协程安全模式
    $supervisor->useContextStorage(true);

    // 创建协程
    Co\create(function() use ($supervisor) {
        // 协程 1 的执行
        $result1 = $supervisor->supervise('任务1', [
            ['role' => 'analyst', 'task' => '分析任务1'],
        ]);

        // 这个协程的执行历史是独立的
        $history1 = ExecutionContext::getHistoryFromContext();
    });

    Co\create(function() use ($supervisor) {
        // 协程 2 的执行（完全独立）
        $result2 = $supervisor->supervise('任务2', [
            ['role' => 'executor', 'task' => '执行任务2'],
        ]);

        // 这个协程的执行历史是独立的
        $history2 = ExecutionContext::getHistoryFromContext();
    });
});
```

### 4.5 OpenSwoole 协程环境示例

```php
use OpenSwoole\Coroutine as Co;
use Kode\AiAgent\Agent\SupervisorAgent;

Co\run(function() {
    $supervisor = new SupervisorAgent(/* ... */);
    $supervisor->useContextStorage(true);

    // 并发执行多个任务
    $promises = [];
    for ($i = 1; $i <= 10; $i++) {
        $promises[] = Co\create(function() use ($supervisor, $i) {
            return $supervisor->supervise("任务{$i}", [
                ['role' => 'analyst', 'task' => "分析任务{$i}"],
            ]);
        });
    }

    // 等待所有任务完成
    foreach ($promises as $promise) {
        $result = $promise->recv();
        echo "结果: {$result->content()}\n";
    }
});
```

---

## 5. 完整使用案例

### 案例 1：软件开发团队

```php
use Kode\AiAgent\Agent\SupervisorAgent;
use Kode\AiAgent\Agent\CostTracker;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

// 创建团队
$team = new SupervisorAgent(
    AdapterFactory::openai('sk-lead-xxx', ['model' => 'gpt-4o'])
);

// 注册团队成员
$team->register('architect',
    AdapterFactory::openai('sk-arch-xxx', ['model' => 'gpt-4o']),
    '架构师 - 负责系统架构设计'
)->register('backend',
    AdapterFactory::deepseek('sk-backend-xxx', ['model' => 'deepseek-chat']),
    '后端工程师 - 负责后端开发'
)->register('frontend',
    AdapterFactory::anthropic('sk-frontend-xxx', ['model' => 'claude-3-5-sonnet']),
    '前端工程师 - 负责前端开发'
)->register('tester',
    AdapterFactory::deepseek('sk-tester-xxx', ['model' => 'deepseek-chat']),
    '测试工程师 - 负责测试'
)->register('devops',
    AdapterFactory::openai('sk-devops-xxx', ['model' => 'gpt-4o']),
    '运维工程师 - 负责部署和运维'
);

// 开发一个博客系统
$result = $team->supervise('开发一个博客系统', [
    ['role' => 'architect', 'task' => '设计博客系统的整体架构，包括技术栈、数据库设计、API 规范'],
    ['role' => 'backend', 'task' => '实现后端 API：用户认证、文章管理、评论系统'],
    ['role' => 'frontend', 'task' => '实现前端页面：文章列表、详情页、编辑页、用户中心'],
    ['role' => 'tester', 'task' => '编写单元测试和集成测试，覆盖核心功能'],
    ['role' => 'devops', 'task' => '编写 Docker 配置、CI/CD 流水线、部署脚本'],
], ['track_cost' => true]);

echo "博客系统开发完成！\n";
echo "总成本: " . $team->costTracker()->formattedCost() . "\n";
```

### 案例 2：市场研究团队

```php
$researchTeam = new SupervisorAgent(
    AdapterFactory::openai('sk-lead-xxx', ['model' => 'gpt-4o'])
);

$researchTeam->register('analyst',
    AdapterFactory::deepseek('sk-xxx'),
    '行业分析师'
)->register('writer',
    AdapterFactory::anthropic('sk-xxx'),
    '报告撰写员'
);

// 自动分解并执行研究任务
$result = $researchTeam->auto('研究 AI 在医疗领域的应用现状及发展趋势');
```

### 案例 3：教育辅导 Agent

```php
$tutor = new SupervisorAgent(
    AdapterFactory::openai('sk-tutor-xxx', ['model' => 'gpt-4o'])
);

$tutor->register('explainer',
    AdapterFactory::anthropic('sk-xxx'),
    '概念解释专家'
)->register('examiner',
    AdapterFactory::deepseek('sk-xxx'),
    '习题出题专家'
)->register('mentor',
    AdapterFactory::openai('sk-xxx'),
    '学习导师'
);

// 辅导学生
$result = $tutor->supervise('辅导高中数学函数章节', [
    ['role' => 'explainer', 'task' => '讲解函数的基本概念、定义域、值域'],
    ['role' => 'examiner', 'task' => '出5道练习题检验理解程度'],
    ['role' => 'mentor', 'task' => '根据学生表现提供个性化学习建议'],
]);
```

### 案例 4：并行竞品分析

```php
$analysisTeam = new SupervisorAgent(
    AdapterFactory::openai('sk-xxx', ['model' => 'gpt-4o'])
);

$analysisTeam->register('analyst',
    AdapterFactory::deepseek('sk-xxx'),
    '竞品分析师'
);

// 并行分析多个竞品
$results = $analysisTeam->parallel([
    ['role' => 'analyst', 'task' => '分析竞品 A 的功能特性'],
    ['role' => 'analyst', 'task' => '分析竞品 B 的功能特性'],
    ['role' => 'analyst', 'task' => '分析竞品 C 的功能特性'],
], ['concurrency' => 3]);

// 汇总分析
$summary = "竞品分析报告：\n";
foreach ($results as $result) {
    $summary .= $result['response']->content() . "\n\n";
}
```

---

## 6. 最佳实践

### 6.1 角色设计

- **清晰职责**：每个角色应有明确的职责范围
- **专业分工**：让专家做专业的事
- **适量角色**：一般 3-5 个角色效果最佳

```php
// ✅ 好：职责清晰
->register('architect', $agent, '负责系统架构设计')
->register('backend', $agent, '负责后端开发')
->register('frontend', $agent, '负责前端开发')

// ❌ 差：职责重叠
->register('dev1', $agent, '负责开发')
->register('dev2', $agent, '负责开发')
```

### 6.2 成本控制

```php
// 使用低成本模型处理简单任务
$supervisor->register('summarizer',
    AdapterFactory::deepseek('sk-xxx', ['model' => 'deepseek-chat']),
    '摘要生成'
);

// 使用高成本模型处理复杂任务
$supervisor->register('architect',
    AdapterFactory::openai('sk-xxx', ['model' => 'gpt-4o']),
    '架构设计'
);

// 启用成本追踪
$result = $supervisor->supervise($goal, $workflow, ['track_cost' => true]);
```

### 6.3 超时控制

```php
// 为不同任务设置不同的超时时间
$context = ExecutionContext::create(
    id: 'task-001',
    task: '复杂分析任务',
    role: 'analyst',
    options: [
        'timeout' => 120,  // 2分钟
        'max_attempts' => 2,
    ]
);
```

### 6.4 错误处理

```php
try {
    $result = $supervisor->supervise($goal, $workflow);
} catch (PlatformException $e) {
    // 平台调用失败
    echo "平台错误: {$e->getMessage()}\n";
    echo "错误码: {$e->errorCode()}\n";
} catch (TimeoutException $e) {
    // 超时
    echo "任务超时\n";
} catch (\Throwable $e) {
    // 其他错误
    echo "未知错误: {$e->getMessage()}\n";
}
```

### 6.5 协程安全检查清单

```php
// ✅ 在 Swoole 环境中
Co\run(function() {
    // 1. 启用协程安全存储
    $supervisor->useContextStorage(true);

    // 2. 使用 runWithContext
    $result = $supervisor->runWithContext($goal, $workflow);

    // 3. 获取隔离的执行历史
    $history = ExecutionContext::getHistoryFromContext();
});

// ❌ 在 Swoole 环境中不要这样做
$supervisor->useContextStorage(false);  // 禁用协程安全！
$result = $supervisor->supervise($goal, $workflow);  // 可能导致数据混乱
```

---

## 更多资源

- [主 README 文档](../README.md)
- [API 文档](./API.md)
- [示例代码](../examples/)
- [贡献指南](./CONTRIBUTING.md)

---

**版本**: v1.6.0
**更新日期**: 2026-03-06
**维护者**: KodePHP Team
