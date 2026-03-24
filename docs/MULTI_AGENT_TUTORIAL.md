# 多 Agent 协作与短剧生成教程

本教程详细介绍如何使用 kode/ai-agent 实现多 Agent 协作进行短剧生成。

## 目录

1. [核心概念](#1-核心概念)
2. [基础架构](#2-基础架构)
3. [单一 Agent 快速开始](#3-单一-agent-快速开始)
4. [多 Agent 协作模式](#4-多-agent-协作模式)
5. [短剧生成完整流程](#5-短剧生成完整流程)
6. [高级用法](#6-高级用法)
7. [最佳实践](#7-最佳实践)

---

## 1. 核心概念

### 1.1 什么是多 Agent 协作？

多 Agent 协作是指多个 AI Agent 共同完成一个复杂任务，每个 Agent 负责特定的子任务，通过分工合作提高效率和质量。

```
┌─────────────────────────────────────────────────────────────┐
│                      Supervisor (主管 Agent)                  │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐          │
│  │ 编剧 Agent  │  │ 画师 Agent  │  │ 剪辑 Agent  │          │
│  │             │→│             │→│             │→ 最终作品  │
│  │ 生成剧本    │  │ 生成图像    │  │ 合成视频    │          │
│  └─────────────┘  └─────────────┘  └─────────────┘          │
└─────────────────────────────────────────────────────────────┘
```

### 1.2 适用场景

| 场景 | Agent 配置 | 说明 |
|------|-----------|------|
| 短剧生成 | 编剧+画师+剪辑 | 分工明确流水线作业 |
| 产品展示 | 文案+图像+配音 | 多模态内容生产 |
| 新闻视频 | 采集+编辑+播报 | 自动化新闻生产 |
| 教育课程 | 讲师+动画+剪辑 | 课件自动化制作 |

---

## 2. 基础架构

### 2.1 核心组件

```
kode/ai-agent
├── Agent               # 核心 Agent
├── DramAgentV2         # 短剧生成 Agent
├── SupervisorAgent     # 主管 Agent（多 Agent 协调）
├── MultimodalService   # 多模态服务
├── FiberPool          # Fiber 协程池（并行处理）
├── ProcessPoolManager  # 进程池（多进程处理）
└── VideoComposerV3    # 视频合成器
```

### 2.2 平台适配器

| 平台 | 类 | 支持能力 |
|------|-----|---------|
| OpenAI | `OpenAiAdapter` | 对话、文生图、图生视频 |
| Anthropic | `AnthropicAdapter` | 对话、图像 |
| 阿里云 | `AliyunAdapter` | 通义千问、文心一言等 |
| 自定义 | `CustomAdapter` | 根据 MultimodalInterface 实现 |

---

## 3. 单一 Agent 快速开始

### 3.1 创建基础 Agent

```php
use Kode\AiAgent\Agent;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;
use Kode\AiAgent\Log\LogManager;

// 初始化日志
LogManager::init(['env' => 'dev']);

// 创建 Agent
$agent = new Agent(
    adapter: AdapterFactory::openai('sk-your-api-key'),
    config: [
        'model' => 'gpt-4',
        'temperature' => 0.7,
        'max_tokens' => 2000,
    ]
);

// 发送消息
$response = $agent->chat('你好，请介绍一下自己');
echo $response->content();
```

### 3.2 多模态 Agent（文生图/视频）

```php
use Kode\AiAgent\Application\Service\MultimodalService;
use Kode\AiAgent\Support\Facade\Multimodal;

// 使用门面
Multimodal::init('sk-your-api-key');

// 文生图
$image = Multimodal::generateImage('一幅 sunset 风景画');
echo $image->firstImage();

// 图生视频
$video = Multimodal::imageToVideo(
    $image->firstImage(),
    '海浪轻轻拍打沙滩'
);
echo $video->firstVideo();
```

---

## 4. 多 Agent 协作模式

### 4.1 主管 Agent 模式

主管 Agent 负责任务分解和结果汇总：

```php
use Kode\AiAgent\Agent\SupervisorAgent;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

$supervisor = new SupervisorAgent(
    adapter: AdapterFactory::openai('sk-supervisor-key'),
    config: [
        'maxAgents' => 5,
        'costTracker' => true,
    ]
);

// 注册子 Agent
$supervisor->registerAgent('writer', $writerAgent);
$supervisor->registerAgent('artist', $artistAgent);
$supervisor->registerAgent('editor', $editorAgent);

// 执行任务
$result = $supervisor->execute('生成一个关于友情的短剧');
```

### 4.2 流水线模式

各 Agent 按顺序执行，形成流水线：

```php
use Kode\AiAgent\Agent\Pipeline\PipelineAgent;

$pipeline = new PipelineAgent();

// 添加流水线节点
$pipeline->pipe('script', function($context) {
    // 编剧 Agent：生成剧本
    return $this->writerAgent->generateScript($context['topic']);
})
->pipe('images', function($context) {
    // 画师 Agent：根据剧本生成图像
    return $this->artistAgent->generateImages($context['script']);
})
->pipe('video', function($context) {
    // 剪辑 Agent：合成视频
    return $this->editorAgent->composeVideo($context['images']);
});

// 执行流水线
$result = $pipeline->execute(['topic' => '友情主题短剧']);
```

### 4.3 并行执行模式

多个 Agent 同时工作，提高效率：

```php
use Kode\AiAgent\Async\FiberPool;

// 创建 Fiber 池
$pool = new FiberPool(concurrency: 4);

// 并行生成多个场景
$tasks = [];
for ($i = 1; $i <= 5; $i++) {
    $sceneId = $i;
    $tasks[] = $pool->submit(fn() => generateScene($sceneId));
}

// 执行并等待
$pool->runAndWait();

foreach ($tasks as $task) {
    echo $task->getResult();
}
```

### 4.4 完整多 Agent 协作示例

```php
use Kode\AiAgent\Agent;
use Kode\AiAgent\Agent\SupervisorAgent;
use Kode\AiAgent\Application\Service\MultimodalService;
use Kode\AiAgent\Drama\DramAgentV2;
use Kode\AiAgent\Async\ParallelExecutor;
use Kode\AiAgent\Log\LogManager;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

/**
 * 多 Agent 协作短剧生成系统
 */
final class MultiAgentDramaSystem
{
    private Agent $scriptAgent;      // 编剧 Agent
    private MultimodalService $imageAgent;  // 画师 Agent
    private DramAgentV2 $videoAgent; // 剪辑 Agent
    private ParallelExecutor $executor;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'script_model' => 'gpt-4',
            'image_model' => 'dall-e-3',
            'video_model' => 'sora',
            'concurrency' => 4,
        ], $config);

        // 初始化各 Agent
        $this->scriptAgent = new Agent(
            AdapterFactory::openai($config['api_key'] ?? 'sk-xxx'),
            ['model' => $this->config['script_model']]
        );

        $this->imageAgent = new MultimodalService(
            AdapterFactory::openai($config['api_key'] ?? 'sk-xxx')
        );

        $this->videoAgent = new DramAgentV2(
            AdapterFactory::openai($config['api_key'] ?? 'sk-xxx'),
            ['scenes' => 5]
        );

        $this->executor = new ParallelExecutor(
            $this->config['concurrency'],
            true
        );

        LogManager::init(['env' => 'dev']);
    }

    /**
     * 一键生成短剧（多 Agent 协作）
     */
    public function generate(string $topic, array $options = []): array
    {
        LogManager::info('开始多 Agent 协作生成短剧', ['topic' => $topic]);

        // Step 1: 编剧 Agent - 生成剧本
        $script = $this->generateScript($topic);
        LogManager::info('剧本生成完成', ['script_length' => strlen($script)]);

        // Step 2: 并行生成场景图像（画师 Agent）
        $scenes = $this->splitScript($script, $options['scenes'] ?? 5);
        $images = $this->generateImagesParallel($scenes);
        LogManager::info('图像生成完成', ['images_count' => count($images)]);

        // Step 3: 图生视频（画师 Agent）
        $videos = $this->generateVideosParallel($images);
        LogManager::info('视频生成完成', ['videos_count' => count($videos)]);

        // Step 4: 剪辑 Agent - 合成最终视频
        $finalVideo = $this->composeFinalVideo($videos, $options);
        LogManager::info('最终视频合成完成', ['output' => $finalVideo]);

        return [
            'topic' => $topic,
            'script' => $script,
            'scenes' => $scenes,
            'images' => $images,
            'videos' => $videos,
            'final_video' => $finalVideo,
        ];
    }

    /**
     * 编剧 Agent：生成剧本
     */
    private function generateScript(string $topic): string
    {
        $prompt = "请为以下主题创作一个 5 幕短剧剧本：\n\n主题：{$topic}\n\n要求：
1. 每幕包含场景描述和对话
2. 总时长约 1-2 分钟
3. 情节完整，有起承转合
4. 语言生动，画面感强";

        $response = $this->scriptAgent->chat($prompt);
        return $response->content();
    }

    /**
     * 分割剧本为场景
     */
    private function splitScript(string $script, int $sceneCount): array
    {
        $parts = preg_split('/第[一二三四五\d]+幕|第[\d]+场/', $script);
        $parts = array_filter($parts, fn($p) => trim($p) !== '');

        if (count($parts) <= $sceneCount) {
            return array_map(fn($p, $i) => [
                'id' => "scene-{$i}",
                'order' => $i,
                'description' => trim($p),
            ], $parts, array_keys($parts));
        }

        $chunks = array_chunk($parts, (int) ceil(count($parts) / $sceneCount));
        return array_map(function ($chunk, $i) {
            return [
                'id' => "scene-{$i}",
                'order' => $i,
                'description' => implode("\n", $chunk),
            ];
        }, array_slice($chunks, 0, $sceneCount), array_keys($chunks));
    }

    /**
     * 画师 Agent：并行生成图像
     */
    private function generateImagesParallel(array $scenes): array
    {
        $tasks = array_map(
            fn($scene) => fn() => $this->imageAgent->generateImage(
                $scene['description'],
                ['style' => 'cinematic', 'size' => '1024x1024']
            ),
            $scenes
        );

        return $this->executor->executeBatch($tasks);
    }

    /**
     * 画师 Agent：并行生成视频
     */
    private function generateVideosParallel(array $images): array
    {
        $tasks = array_map(
            fn($image, $i) => fn() => $this->imageAgent->imageToVideo(
                $image->firstImage(),
                "场景 {$i} 的动态效果",
                ['duration' => 5]
            ),
            $images,
            array_keys($images)
        );

        return $this->executor->executeBatch($tasks);
    }

    /**
     * 剪辑 Agent：合成最终视频
     */
    private function composeFinalVideo(array $videos, array $options): string
    {
        $sceneVideos = array_map(function ($video, $i) {
            return new \Kode\AiAgent\Drama\SceneVideo(
                sceneId: "scene-{$i}",
                order: $i,
                videoUrl: $video->firstVideo(),
                duration: 5,
            );
        }, $videos, array_keys($videos));

        $composer = new \Kode\AiAgent\Video\VideoComposerV3();
        $composer->addSceneVideos($sceneVideos);

        if ($options['background_music'] ?? false) {
            $composer->setBackgroundMusic($options['background_music'], 0.3);
        }

        $result = $composer->compose();
        return $result['output'];
    }
}

// 使用示例
$system = new MultiAgentDramaSystem([
    'api_key' => 'sk-your-api-key',
    'concurrency' => 4,
]);

$result = $system->generate('一个关于友情的感人故事', [
    'scenes' => 5,
    'background_music' => '/path/to/bgm.mp3',
]);

echo "最终视频：{$result['final_video']}\n";
```

---

## 5. 短剧生成完整流程

### 5.1 流程图

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        短剧生成完整流程                                  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌─────────┐    ┌─────────┐    ┌─────────┐    ┌─────────┐             │
│  │  剧本   │ → │  拆分   │ → │  文生图  │ → │  图生视频 │             │
│  │ Script  │    │ Scenes │    │ T2I     │    │ I2V     │             │
│  └─────────┘    └─────────┘    └─────────┘    └─────────┘             │
│       ↓             ↓             ↓             ↓                    │
│  ┌─────────┐    ┌─────────┐    ┌─────────┐    ┌─────────┐          │
│  │  编剧   │    │  场景   │    │  画师   │    │  画师   │          │
│  │ Agent   │    │ Manager │    │ Agent   │    │ Agent   │          │
│  └─────────┘    └─────────┘    └─────────┘    └─────────┘          │
│                                                         ↓            │
│  ┌─────────┐    ┌─────────┐    ┌─────────┐    ┌─────────┐          │
│  │  输出   │ ← │  合成   │ ← │ 加转场  │ ← │ 配音旁白 │          │
│  │ Output  │    │ Compose │    │Transitions│   │ Voiceover│          │
│  └─────────┘    └─────────┘    └─────────┘    └─────────┘          │
│       ↑             ↓                                                    │
│  ┌─────────┐    ┌─────────┐                                             │
│  │  字幕   │    │  剪辑   │                                             │
│  │Subtitle│    │ Agent   │                                             │
│  └─────────┘    └─────────┘                                             │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

### 5.2 DramAgentV2 完整示例

```php
use Kode\AiAgent\Drama\DramAgentV2;
use Kode\AiAgent\Drama\EnhancedScene;
use Kode\AiAgent\Drama\SceneType;
use Kode\AiAgent\Drama\TransitionType;
use Kode\AiAgent\Drama\FrameVideo;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;
use Kode\AiAgent\Log\LogManager;
use Kode\AiAgent\Voice\VoiceoverGenerator;
use Kode\AiAgent\Voice\VoiceRole;
use Kode\AiAgent\Voice\VoiceStyle;
use Kode\AiAgent\Subtitle\SubtitleGenerator;

// 初始化
LogManager::init(['env' => 'dev']);
AdapterFactory::setDefault('openai', 'sk-your-api-key');

// 创建短剧 Agent
$agent = new DramAgentV2(
    adapter: AdapterFactory::openai('sk-your-api-key'),
    config: [
        'scenes' => 5,
        'duration_per_scene' => 10,
        'style' => 'cinematic',
        'transition_type' => 'fade',
        'transition_duration' => 1,
        'image_size' => '1920x1080',
        'video_resolution' => '1080p',
        'concurrency' => 4,
        'enable_parallel' => true,
    ]
);

// 生成短剧
$script = <<<'SCRIPT'
第一幕：阳光明媚的早晨，主人公小明在公园散步。
第二幕：他突然看到一只受伤的小鸟落在草地上。
第三幕：小明小心翼翼地捧起小鸟，带回家中治疗。
第四幕：几天后，小鸟康复了，小明依依不舍地放飞它。
第五幕：小鸟回头看了小明一眼，然后飞向蓝天。
SCRIPT;

$result = $agent->generate($script, [
    'title' => '友情的故事',
    'reference_image' => 'https://example.com/style.jpg',
    'opening' => [
        'title' => '精彩故事即将开始',
        'duration' => 3,
    ],
    'closing' => [
        'text' => '感谢观看',
        'duration' => 5,
    ],
    'background_music' => '/path/to/bgm.mp3',
    'music_volume' => 0.3,
]);

echo "短剧生成完成！\n";
echo "输出视频：{$result->video}\n";
echo "场景数量：{$result->scenesCount()}\n";
echo "总时长：{$result->totalDuration()}秒\n";
```

### 5.3 分步执行示例

```php
use Kode\AiAgent\Drama\DramAgentV2;
use Kode\AiAgent\Drama\StoryBoardV2;
use Kode\AiAgent\Drama\EnhancedScene;
use Kode\AiAgent\Drama\TransitionManager;
use Kode\AiAgent\Drama\TransitionType;
use Kode\AiAgent\Drama\FrameVideo;
use Kode\AiAgent\Video\VideoComposerV3;
use Kode\AiAgent\Video\VideoClipper;
use Kode\AiAgent\Voice\VoiceoverGenerator;
use Kode\AiAgent\Voice\VoiceRole;
use Kode\AiAgent\Voice\VoiceStyle;
use Kode\AiAgent\Subtitle\SubtitleGenerator;

// Step 1: 剧本解析
$agent = new DramAgentV2($adapter);

$script = "在一个遥远的星球上，住着一个爱冒险的小王子...";
$storyBoard = $agent->parseScript($script, [
    'scenes' => 8,
    'style' => 'fantasy',
]);

echo "剧本解析完成，共 " . $storyBoard->scenesCount() . " 个场景\n";

// Step 2: 生成增强场景
$scenes = $agent->generateEnhancedScenes($storyBoard, [
    'reference_image' => 'https://example.com/fantasy-style.jpg',
]);

echo "场景图像生成完成\n";

// Step 3: 生成场景视频
$sceneVideos = $agent->generateSceneVideos($scenes, [
    'video_resolution' => '1080p',
]);

echo "场景视频生成完成\n";

// Step 4: 配置转场效果
$composer = new VideoComposerV3();
$composer->addSceneVideos($sceneVideos);

// 添加转场
$transitionManager = $composer->getTransitionManager();
for ($i = 0; $i < count($sceneVideos) - 1; $i++) {
    $composer->addTransition(
        "scene-{$i}",
        "scene-" . ($i + 1),
        TransitionType::FADE,
        1
    );
}

// Step 5: 设置开场/结尾
$composer->setOpening(FrameVideo::opening('https://cdn.example.com/intro.mp4', [
    'title' => '星际冒险',
    'duration' => 3,
]));

$composer->setClosing(FrameVideo::closing('https://cdn.example.com/outro.mp4', [
    'ending_text' => '小王子的冒险还在继续...',
    'duration' => 5,
]));

// Step 6: 合成最终视频
$result = $composer->compose([
    'background_music' => '/path/to/space-bgm.mp3',
    'music_volume' => 0.3,
]);

echo "视频合成完成：{$result['output']}\n";

// Step 7: 添加配音旁白
$voiceover = new VoiceoverGenerator();

$narration = $voiceover->generateFromScript($script, [
    'role' => VoiceRole::NARRATOR,
    'style' => VoiceStyle::FRIENDLY,
]);

$videoWithVoice = $voiceover->addToVideo($result['output'], $narration[0], [
    'audio_volume' => 1.0,
    'video_volume' => 0.5,
]);

// Step 8: 生成字幕
$subtitleGenerator = new SubtitleGenerator();
$subtitles = $subtitleGenerator->generateFromVideo($videoWithVoice, [
    'language' => 'zh-CN',
]);

$subtitleGenerator->save($subtitles, '/path/to/subtitles.srt');

// Step 9: 剪辑最终输出
$clipper = new VideoClipper();

// 剪裁前 3 秒开场动画
$clipper->cut($videoWithVoice, 0, 3);

// 添加片尾彩蛋
$clipper->concatenate([
    $videoWithVoice,
    '/path/to/credits.mp4',
], $output);

echo "最终视频输出：{$output}\n";
```

---

## 6. 高级用法

### 6.1 使用 Fiber 实现真正并行

```php
use Kode\AiAgent\Async\FiberPool;

$pool = new FiberPool(concurrency: 10);

// 同时生成 10 个场景
$futures = [];
for ($i = 1; $i <= 10; $i++) {
    $futures[] = $pool->submit(function() use ($i) {
        // 每个 Fiber 独立执行
        $image = $imageAgent->generateImage("场景 {$i} 描述");
        $video = $imageAgent->imageToVideo($image->url, "场景 {$i} 动态效果");
        return $video->url;
    });
}

// 等待所有完成
$pool->runAndWait();

// 获取结果
$videoUrls = array_map(fn($f) => $f->getResult(), $futures);
```

### 6.2 使用进程池处理 CPU 密集任务

```php
use Kode\AiAgent\Process\ProcessPoolManager;

// 创建进程池（用于视频处理）
$pool = new ProcessPoolManager(maxProcesses: 4);

// 提交视频处理任务
$pool->submit('ffmpeg -i input.mp4 -vf "scale=1920:1080" output_1080p.mp4');
$pool->submit('ffmpeg -i input.mp4 -vf "scale=1280:720" output_720p.mp4');
$pool->submit('ffmpeg -i input.mp4 -vf "scale=640:360" output_360p.mp4');
$pool->submit('ffmpeg -i input.mp4 -vn -acodec mp3 output_audio.mp3');

// 执行并等待
$pool->runAndWait();
```

### 6.3 自定义 Agent 实现

```php
use Kode\AiAgent\Agent\Agent;
use Kode\AiAgent\Domain\Contract\AdapterInterface;

class CustomAgent extends Agent
{
    private array $tools = [];

    /**
     * 注册工具
     */
    public function registerTool(string $name, callable $handler, string $description): self
    {
        $this->tools[$name] = [
            'handler' => $handler,
            'description' => $description,
        ];
        return $this;
    }

    /**
     * 使用工具执行任务
     */
    public function useTool(string $name, array $params = []): mixed
    {
        if (!isset($this->tools[$name])) {
            throw new \RuntimeException("未知工具: {$name}");
        }

        return ($this->tools[$name]['handler'])($params);
    }

    /**
     * 获取可用工具列表
     */
    public function getAvailableTools(): array
    {
        return array_map(
            fn($name, $tool) => ['name' => $name, 'description' => $tool['description']],
            array_keys($this->tools),
            $this->tools
        );
    }
}

// 使用自定义 Agent
$agent = new CustomAgent($adapter);

$agent->registerTool('generate_image', function($params) {
    return $this->multimodal->generateImage($params['prompt']);
}, '根据描述生成图像');

$agent->registerTool('play_music', function($params) {
    return $this->musicPlayer->play($params['song']);
}, '播放音乐');
```

### 6.4 成本追踪与预算控制

```php
use Kode\AiAgent\Agent\CostTracker;

$tracker = new CostTracker();

// 设置预算限制
$tracker->setBudget('daily', 100.00);  // 每日预算 100 美元
$tracker->setBudget('monthly', 1000.00); // 每月预算 1000 美元

// 在 SupervisorAgent 中启用
$supervisor = new SupervisorAgent($adapter, [
    'costTracker' => $tracker,
]);

// 检查预算
if ($tracker->checkBudget()) {
    // 继续执行任务
    $result = $supervisor->execute($task);
} else {
    echo "预算超限，请升级套餐或明天重试";
}

// 获取使用报告
$report = $tracker->getReport();
echo "今日消费：{$report['today']['total_cost']}\n";
echo "Token 使用：{$report['today']['total_tokens']}\n";
```

---

## 7. 最佳实践

### 7.1 Agent 设计原则

1. **单一职责**：每个 Agent 只负责一个特定任务
2. **清晰接口**：Agent 之间通过定义好的接口通信
3. **错误处理**：每个 Agent 独立处理错误，不影响其他 Agent
4. **资源管理**：合理设置超时和重试次数

### 7.2 性能优化

| 场景 | 建议 |
|------|------|
| 多场景并行 | 使用 FiberPool，控制并发数 4-10 |
| CPU 密集任务 | 使用 ProcessPoolManager |
| 视频处理 | 使用 FFmpeg 批量处理 |
| API 调用 | 启用请求缓存和响应缓存 |

### 7.3 安全建议

1. **API Key 管理**：使用环境变量，不要硬编码
2. **敏感信息**：日志输出时自动脱敏
3. **输入验证**：验证所有用户输入
4. **异常处理**：捕获并记录所有异常

### 7.4 监控与日志

```php
use Kode\AiAgent\Log\LogManager;

LogManager::init([
    'env' => 'prod',
    'path' => 'var/log/ai-agent.log',
]);

// 分频道日志
$videoLogger = LogManager::channel('video');
$videoLogger->info('视频处理开始', ['video_id' => $id]);

$costLogger = LogManager::channel('cost');
$costLogger->info('Token 消耗', ['tokens' => $usage, 'cost' => $cost]);
```

---

## 附录：完整文件结构

```
kode/ai-agent/
├── src/
│   ├── Agent/                    # Agent 核心
│   │   ├── Agent.php
│   │   ├── SupervisorAgent.php   # 主管 Agent
│   │   ├── ExecutionContext.php
│   │   ├── CostTracker.php
│   │   └── AgentMemory.php
│   ├── Application/Service/     # 应用服务
│   │   └── MultimodalService.php
│   ├── Drama/                    # 短剧生成
│   │   ├── DramAgentV2.php
│   │   ├── EnhancedScene.php
│   │   ├── TransitionManager.php
│   │   └── FrameVideo.php
│   ├── Video/                    # 视频处理
│   │   ├── VideoComposerV3.php
│   │   └── VideoClipper.php
│   ├── Voice/                    # 配音旁白
│   │   └── VoiceoverGenerator.php
│   ├── Subtitle/                 # 字幕生成
│   │   └── SubtitleGenerator.php
│   ├── Async/                    # 异步处理
│   │   ├── FiberPool.php
│   │   └── ParallelExecutor.php
│   ├── Process/                  # 进程管理
│   │   ├── SystemProcess.php
│   │   └── ProcessPoolManager.php
│   └── Log/                      # 日志系统
│       ├── LogManager.php
│       └── LoggerFactory.php
├── docs/
│   ├── README.md
│   ├── ADVANCED_GUIDE.md
│   └── MULTI_AGENT_TUTORIAL.md   # 本教程
└── tests/
```

---

**版本**: v2.4.0
**更新日期**: 2026-03-24
**维护者**: KodePHP Team