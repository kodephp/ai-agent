# Kode AI Agent 高级功能开发指南

本指南详细介绍 kode/ai-agent 的高级功能，包括分工代理、主管 Agent、成本追踪、协程安全、记忆系统、重试策略等特性。

## 目录

1. [主管 Agent（SupervisorAgent）](#1-主管-agentsupervisoragent)
2. [执行上下文（ExecutionContext）](#2-执行上下文-executioncontext)
3. [成本追踪器（CostTracker）](#3-成本追踪器-costtracker)
4. [记忆系统（AgentMemory）](#4-记忆系统-agentmemory)
5. [重试策略（RetryStrategy）](#5-重试策略-retrystrategy)
6. [协程安全编程](#6-协程安全编程)
7. [完整使用案例](#7-完整使用案例)
8. [最佳实践](#8-最佳实践)

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

### 1.4 自动任务分解（auto）

```php
$result = $supervisor->auto('请完成一个用户认证系统的开发');
```

### 1.5 并行执行（parallel）

```php
$results = $supervisor->parallel([
    ['role' => 'analyst', 'task' => '分析性能需求'],
    ['role' => 'analyst', 'task' => '分析安全需求'],
], ['concurrency' => 2]);
```

---

## 2. 执行上下文（ExecutionContext）

ExecutionContext 管理单个任务的生命周期。

### 2.1 基本使用

```php
use Kode\AiAgent\Agent\ExecutionContext;

$context = new ExecutionContext(
    id: 'task-001',
    task: '实现用户认证功能',
    role: 'executor',
    options: [
        'max_attempts' => 3,
        'timeout' => 60,
        'use_context_storage' => true,
    ]
);

$context->start();

if ($success) {
    $context->complete(['result' => 'success']);
} else {
    $context->fail('任务失败原因');
}
```

### 2.2 状态检查

```php
if ($context->isTimeout()) {
    echo "任务超时\n";
}

if ($context->canRetry()) {
    echo "可以重试\n";
}
```

---

## 3. 成本追踪器（CostTracker）

### 3.1 基本使用

```php
use Kode\AiAgent\Agent\CostTracker;

$tracker = new CostTracker(currency: 'USD', enabled: true);

$tracker->track(
    model: 'gpt-4o',
    promptTokens: 100,
    completionTokens: 200,
    metadata: ['user_id' => '123']
);

echo $tracker->formattedCost();   // "0.003750 USD"
echo $tracker->formattedTokens(); // "750"
```

### 3.2 自定义模型价格

```php
$tracker->setModelPrice('gpt-4o', 0.0000025, 0.00001);
$tracker->setModelPrice('claude-3-5-sonnet', 0.000003, 0.000015);
```

---

## 4. 记忆系统（AgentMemory）

AgentMemory 支持 Agent 之间共享记忆，实现上下文传递。

### 4.1 基本概念

- **短期记忆**：会话级别，任务结束后清除
- **长期记忆**：持久化存储，跨会话保留
- **协程安全**：集成 kode/context 实现隔离

### 4.2 基本使用

```php
use Kode\AiAgent\Agent\AgentMemory;

// 创建记忆系统
$memory = AgentMemory::create(useContextStorage: true);

// 存储短期记忆
$memory->remember('current_task', '用户认证开发', 'analyst');

// 存储长期记忆
$memory->memorize('project_context', [
    'tech_stack' => 'PHP + React',
    'database' => 'PostgreSQL',
], 'architect');

// 回忆记忆
$task = $memory->recall('current_task');

// 检查记忆是否存在
if ($memory->has('project_context')) {
    $context = $memory->recall('project_context');
}

// 遗忘记忆
$memory->forget('current_task');
```

### 4.3 按角色管理记忆

```php
// 获取特定角色的所有记忆
$analystMemories = $memory->byRole('analyst');

// 获取最近的记忆
$recentMemories = $memory->recent(limit: 10);
```

### 4.4 构建上下文提示词

```php
// 为特定角色构建上下文提示词
$prompt = $memory->buildContextPrompt('analyst');

// 输出示例：
// 历史上下文：
// - current_task: 用户认证开发
// - tech_stack: PHP + React
```

### 4.5 记忆导入导出

```php
// 导出记忆
$data = $memory->export();

// 导入记忆
$memory->import($data);

// 清空短期记忆
$memory->clearShortTerm();

// 清空所有记忆
$memory->clear();
```

### 4.6 在 SupervisorAgent 中使用

```php
use Kode\AiAgent\Agent\SupervisorAgent;
use Kode\AiAgent\Agent\AgentMemory;

$supervisor = new SupervisorAgent(/* ... */);
$memory = AgentMemory::create(true);

// 注册 Agent 时存储记忆
$supervisor->register('analyst', $analystAgent);

// 存储分析结果
$memory->remember('analysis_result', $result, 'analyst');

// 后续任务可以回忆
$previousResult = $memory->recall('analysis_result');
```

---

## 5. 重试策略（RetryStrategy）

RetryStrategy 提供灵活的重试机制，支持多种退避策略。

### 5.1 退避策略

- **固定延迟（FIXED）**：每次重试间隔相同时间
- **线性延迟（LINEAR）**：延迟时间线性增长
- **指数退避（EXPONENTIAL）**：延迟时间指数增长

### 5.2 预设策略

```php
use Kode\AiAgent\Agent\RetryStrategy;

// 默认策略：3次重试，指数退避，1秒基础延迟
$retry = RetryStrategy::default();

// 快速策略：3次重试，无延迟
$retry = RetryStrategy::fast();

// 保守策略：5次重试，指数退避，2秒基础延迟
$retry = RetryStrategy::conservative();
```

### 5.3 自定义策略

```php
$retry = new RetryStrategy([
    'max_attempts' => 5,
    'base_delay_ms' => 1000,
    'max_delay_ms' => 60000,
    'multiplier' => 2.0,
    'strategy' => RetryStrategy::STRATEGY_EXPONENTIAL,
]);

// 链式配置
$retry = (new RetryStrategy())
    ->maxAttempts(5)
    ->baseDelay(2000)
    ->maxDelay(60000)
    ->strategy(RetryStrategy::STRATEGY_EXPONENTIAL);
```

### 5.4 可重试错误配置

```php
use Kode\AiAgent\Exception\PlatformException;
use Kode\AiAgent\Exception\TimeoutException;

$retry = (new RetryStrategy())
    ->maxAttempts(3)
    ->retryableErrors([
        PlatformException::class,
        TimeoutException::class,
    ]);
```

### 5.5 自定义重试条件

```php
$retry = (new RetryStrategy())
    ->maxAttempts(5)
    ->withRetryCondition(function (int $attempt, ?\Throwable $error) {
        // 自定义逻辑：只在工作日重试
        $dayOfWeek = date('N');
        return $dayOfWeek >= 1 && $dayOfWeek <= 5;
    });
```

### 5.6 执行重试

```php
$result = $retry->execute(function (int $attempt) {
    // 业务逻辑
    return someRiskyOperation();
});

// 带错误处理
try {
    $result = $retry->execute(function (int $attempt) {
        return callExternalApi();
    });
} catch (\Throwable $e) {
    echo "所有重试失败: {$e->getMessage()}\n";
}
```

### 5.7 计算延迟

```php
// 查看每次重试的延迟
for ($i = 1; $i <= 5; $i++) {
    $delay = $retry->calculateDelay($i);
    echo "第 {$i} 次重试延迟: {$delay}ms\n";
}

// 指数退避示例输出：
// 第 1 次重试延迟: 1000ms
// 第 2 次重试延迟: 2000ms
// 第 3 次重试延迟: 4000ms
// 第 4 次重试延迟: 8000ms
// 第 5 次重试延迟: 16000ms
```

---

## 6. 协程安全编程

### 6.1 启用协程安全模式

```php
use Kode\AiAgent\Agent\SupervisorAgent;
use Kode\AiAgent\Agent\ExecutionContext;
use Kode\AiAgent\Agent\AgentMemory;

// SupervisorAgent
$supervisor->useContextStorage(true);

// ExecutionContext
$context = ExecutionContext::create(
    id: 'task-001',
    task: '任务',
    role: 'worker',
    options: ['use_context_storage' => true]
);

// AgentMemory
$memory = AgentMemory::create(useContextStorage: true);
```

### 6.2 Swoole 协程示例

```php
use Swoole\Coroutine as Co;

Co\run(function() use ($supervisor) {
    $supervisor->useContextStorage(true);

    Co\create(function() use ($supervisor) {
        $result = $supervisor->supervise('任务1', [/*...*/]);
    });

    Co\create(function() use ($supervisor) {
        $result = $supervisor->supervise('任务2', [/*...*/]);
    });
});
```

---

## 7. 完整使用案例

### 案例 1：带记忆的软件开发团队

```php
use Kode\AiAgent\Agent\SupervisorAgent;
use Kode\AiAgent\Agent\AgentMemory;
use Kode\AiAgent\Agent\RetryStrategy;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

// 创建记忆系统
$memory = AgentMemory::create(true);

// 创建团队
$team = new SupervisorAgent(
    AdapterFactory::openai('sk-lead-xxx', ['model' => 'gpt-4o'])
);

$team->register('architect', AdapterFactory::openai('sk-xxx'), '架构师')
     ->register('backend', AdapterFactory::deepseek('sk-xxx'), '后端工程师')
     ->register('frontend', AdapterFactory::anthropic('sk-xxx'), '前端工程师');

// 存储项目上下文
$memory->memorize('project', [
    'name' => '博客系统',
    'tech_stack' => ['PHP', 'React', 'PostgreSQL'],
], 'architect');

// 创建重试策略
$retry = RetryStrategy::conservative();

// 执行开发任务
$result = $retry->execute(function () use ($team, $memory) {
    // 获取上下文
    $projectContext = $memory->buildContextPrompt('architect');

    return $team->supervise($projectContext . '开发博客系统', [
        ['role' => 'architect', 'task' => '设计系统架构'],
        ['role' => 'backend', 'task' => '实现后端 API'],
        ['role' => 'frontend', 'task' => '实现前端页面'],
    ], ['track_cost' => true]);
});

// 存储结果
$memory->remember('last_result', $result->content(), 'supervisor');
```

---

## 8. 最佳实践

### 8.1 记忆管理

```php
// ✅ 好：区分短期和长期记忆
$memory->remember('temp_data', $temp);      // 短期
$memory->memorize('config', $config);       // 长期

// ✅ 好：按角色组织记忆
$memory->remember('task', $task, 'analyst');

// ❌ 差：所有记忆都存长期
$memory->memorize('temp', $temp);  // 临时数据不应长期存储
```

### 8.2 重试策略

```php
// ✅ 好：根据场景选择策略
$apiRetry = RetryStrategy::default();        // API 调用
$fastRetry = RetryStrategy::fast();          // 快速操作
$conservativeRetry = RetryStrategy::conservative(); // 重要操作

// ✅ 好：限制可重试的错误
$retry->retryableErrors([TimeoutException::class]);

// ❌ 差：无限重试
$retry->maxAttempts(PHP_INT_MAX);  // 危险！
```

### 8.3 成本控制

```php
// ✅ 好：追踪成本
$result = $supervisor->supervise($goal, $workflow, ['track_cost' => true]);
$cost = $supervisor->costTracker()->formattedCost();

// ✅ 好：使用低成本模型处理简单任务
$supervisor->register('summarizer',
    AdapterFactory::deepseek('sk-xxx'),  // 低成本
    '摘要生成'
);
```

---

**版本**: v1.8.0
**更新日期**: 2026-03-24
**维护者**: KodePHP Team

---

## 9. 短剧生成系统（DramAgent）

### 9.1 概述

DramAgent 提供完整的短剧生成工作流，支持一键生成短视频：

1. **剧本解析**：将剧本拆分为多个场景
2. **场景图像生成**：使用 AI 生成每个场景的图像
3. **图像转视频**：将静态图像转换为动态视频
4. **数字人合成**：添加数字人口播内容
5. **视频合成**：将多个视频片段合并为完整短剧

### 9.2 基本使用

```php
use Kode\AiAgent\Drama\DramAgent;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;
use Kode\AiAgent\Log\LogManager;

// 初始化日志
LogManager::init(['env' => 'dev']);

// 创建 DramAgent
$agent = new DramAgent(
    adapter: AdapterFactory::openai('sk-xxx'),
    config: [
        'scenes' => 5,
        'duration_per_scene' => 10,
        'style' => 'cinematic',
        'enable_parallel' => true,
        'concurrency' => 4,
    ],
);

// 一键生成短剧
$result = $agent->generate('在一个阳光明媚的早晨，小明和小红相遇了...');

echo "视频地址: {$result->video}\n";
echo "总时长: {$result->duration}秒\n";
```

### 9.3 分步生成

```php
// 1. 解析剧本
$storyBoard = $agent->parseScript($script, [
    'scenes' => 8,
    'style' => 'cinematic',
]);

// 2. 生成场景图像
$scenes = $agent->generateSceneImages($storyBoard);

// 3. 生成场景视频
$videos = $agent->generateSceneVideos($scenes, [
    'video_resolution' => '1080p',
]);

// 4. 合成最终视频
$finalVideo = $agent->composeFinalVideo($videos, [
    'transition' => 'fade',
    'background_music' => '/path/to/music.mp3',
]);
```

### 9.4 带数字人的短剧

```php
$result = $agent->generateWithAvatar(
    script: '今天给大家介绍一款新产品...',
    avatarOptions: [
        'avatar_id' => 'default-female',
        'voice_id' => 'voice-female-zh',
        'language' => 'zh-CN',
    ],
    dramaOptions: [
        'scenes' => 5,
    ]
);
```

---

## 10. 日志系统（Monolog 集成）

### 10.1 日志管理器

```php
use Kode\AiAgent\Log\LogManager;

// 初始化
LogManager::init([
    'env' => 'dev',
    'enabled' => true,
]);

// 静态调用
LogManager::info('消息发送成功', ['message_id' => 'xxx']);
LogManager::error('请求失败', ['error' => $e->getMessage()]);
LogManager::warning('频率限制触发', ['retry_after' => 60]);
```

### 10.2 敏感信息脱敏

自动脱敏敏感字段：

```php
LogManager::info('API请求', [
    'api_key' => 'sk-xxx',        // → ***REDACTED***
    'token' => 'Bearer xxx',      // → ***REDACTED***
    'password' => 'secret',        // → ***REDACTED***
    'message' => 'hello',          // 保持原样
]);
```

### 10.3 日志工厂

```php
use Kode\AiAgent\Log\LoggerFactory;

// 文件日志
$logger = LoggerFactory::create([
    'channel' => 'ai-agent',
    'level' => 'debug',
    'path' => 'var/log/ai-agent.log',
    'env' => 'prod',
]);

// 控制台日志
$console = LoggerFactory::console([
    'level' => 'debug',
    'output' => 'php://stdout',
]);
```

---

## 11. 并行处理（Fiber/协程）

### 11.1 Fiber 池

```php
use Kode\AiAgent\Async\FiberPool;

// 创建并发池
$pool = new FiberPool(concurrency: 10);

// 提交任务
$tasks = [];
for ($i = 0; $i < 20; $i++) {
    $tasks[] = $pool->submit(fn() => processImage($i));
}

// 批量执行
$pool->run();

// 获取结果
foreach ($tasks as $task) {
    $result = $task->getResult();
}
```

### 11.2 并行执行器

```php
use Kode\AiAgent\Async\ParallelExecutor;

// 创建执行器
$executor = new ParallelExecutor(
    concurrency: 4,
    enableParallel: true
);

// 批量执行
$results = $executor->executeBatch([
    fn() => generateImage('场景1描述'),
    fn() => generateImage('场景2描述'),
    fn() => generateImage('场景3描述'),
    fn() => generateImage('场景4描述'),
], function ($completed, $total) {
    echo "进度: {$completed}/{$total}\n";
});

// Map 操作
$images = $executor->map($prompts, fn($p) => generateImage($p));
```

---

## 12. 进程管理

### 12.1 进程池

```php
use Kode\AiAgent\Process\ProcessPool;

// 创建进程池
$pool = new ProcessPool(maxProcesses: 4);

// 提交视频处理任务
$pool->submit('ffmpeg -i input.mp4 -vf "scale=1920:1080" output_1080p.mp4');
$pool->submit('ffmpeg -i input.mp4 -vf "scale=1280:720" output_720p.mp4');
$pool->submit('ffmpeg -i input.mp4 -vn -acodec mp3 output.mp3');

// 执行并等待
$outputs = $pool->runAndWait(function ($pid, $output) {
    echo "[PID {$pid}] {$output}\n";
});
```

### 12.2 单个进程

```php
use Kode\AiAgent\Process\Process;

// 创建进程
$process = new Process('ffmpeg -i input.mp4 output.mp4', [
    'timeout' => 300,
    'buffer_size' => 8192,
]);

// 启动
$process->start();

// 监控输出
while ($process->isRunning()) {
    $output = $process->update();
    if ($output) {
        echo $output;
    }
}

// 等待完成
$process->wait();

// 获取结果
echo $process->getOutput();
echo "Exit code: " . $process->getExitCode();
```

---

## 13. 视频合成

### 13.1 VideoComposerV2

```php
use Kode\AiAgent\Video\VideoComposerV2;

// 创建合成器
$composer = new VideoComposerV2(
    logger: null,
    concurrency: 4,
    config: ['output_dir' => 'var/drama/output']
);

// 合成场景视频
$output = $composer->compose($sceneVideos, [
    'transition' => 'fade',
    'background_music' => '/path/to/music.mp3',
    'music_volume' => 0.3,
]);
```

### 13.2 视频合并

```php
// 合并多个视频
$output = $composer->concatenate([
    '/path/to/video1.mp4',
    '/path/to/video2.mp4',
    '/path/to/video3.mp4',
], ['format' => 'mp4']);
```

### 13.3 视频分段

```php
// 按时长分段
$chunks = $composer->split('/path/to/long-video.mp4', 60);

foreach ($chunks as $chunk) {
    echo "分段: {$chunk['path']}, 时长: {$chunk['duration']}秒\n";
}
```

### 13.4 添加水印

```php
$watermarked = $composer->addWatermark(
    '/path/to/video.mp4',
    '/path/to/watermark.png',
    [
        'position' => '右下',
        'opacity' => 0.3,
        'scale' => 0.2,
    ]
);
```
