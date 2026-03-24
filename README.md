# Kode AI Agent

企业级 PHP AI Agent 层，兼容 Symfony AI 生态，支持协程、管道和 SSE 流式响应。

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](https://opensource.org/licenses/Apache-2.0)

## 特性

- **六边形架构**：核心逻辑与外部依赖解耦，依赖方向正确
- **多平台支持**：OpenAI、Anthropic Claude、DeepSeek、阿里云通义千问、Google Gemini、百度文心一言、腾讯混元、讯飞星火
- **API Key 轮换**：支持单 Key、双 Key（主备）、多 Key 轮换模式
- **工具调用循环**：自动处理 AI 工具调用，支持多轮调用
- **管道中间件**：灵活的请求处理管道，支持缓存、限流、重试
- **流式响应**：内置 SSE (Server-Sent Events) 支持
- **向量数据库**：内置向量存储接口，支持 Milvus、Pinecone、Qdrant 等
- **MCP 协议**：完整的 Model Context Protocol 支持
- **安全增强**：HTTPS 强制检查、API Key 格式验证、日志脱敏
- **PSR 标准**：完全兼容 PSR-7, PSR-18, PSR-17, PSR-3, PSR-11, PSR-16
- **PHP 8.5 就绪**：支持 `#[NoDiscard]` 属性和管道操作符
- **Kode 生态集成**：集成 kode/context、kode/facade、kode/http-client、kode/attributes
- **多模态支持**：文本生成图像、文本生成视频、数字人视频生成
- **文件上传**：支持视频、音频等媒体文件的安全上传
- **进度跟踪**：实时任务进度反馈和状态管理

## 安装

```bash
composer require kode/ai-agent
```

## 依赖包

本项目依赖以下 kode 系列包：

| 包名 | 说明 |
|------|------|
| `kode/tools` | 响应体 Message、字符串 Str、数组 Arr、时间 Time |
| `kode/context` | 协程安全的上下文管理 |
| `kode/facade` | 门面模式支持 |
| `kode/http-client` | 多运行时 HTTP 客户端 |
| `kode/attributes` | 注解解析器 |

## 快速开始

### 1. 使用适配器工厂（推荐）

```php
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

// 快速创建 OpenAI 适配器
$adapter = AdapterFactory::openai('sk-xxx', [
    'model' => 'gpt-4o',
    'timeout' => 30,
]);

// 快速创建 Anthropic 适配器
$adapter = AdapterFactory::anthropic('sk-ant-xxx');

// 快速创建 Gemini 适配器
$adapter = AdapterFactory::gemini('AIza-xxx');

// 通用创建方式
$adapter = AdapterFactory::create('deepseek', [
    'api_key' => 'sk-xxx',
    'model' => 'deepseek-chat',
]);
```

### 2. 使用构建器

```php
use Kode\AiAgent\Support\Builder\AgentBuilder;

// 构建适配器
$adapter = AgentBuilder::create()
    ->withPlatform('openai')
    ->withApiKey('sk-xxx')
    ->withModel('gpt-4o')
    ->withTemperature(0.7)
    ->withTimeout(60)
    ->withRetry(3, 1000)
    ->build();

// 构建 Agent（包含工具）
$agent = AgentBuilder::create()
    ->withPlatform('openai')
    ->withApiKey('sk-xxx')
    ->withSystemPrompt('你是一个有用的助手')
    ->withTool('calculator', '计算器', fn($a, $b) => $a + $b)
    ->withMaxToolCalls(5)
    ->buildAgent();
```

### 3. 使用门面类

```php
use Kode\AiAgent\Support\Facade\Ai;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

// 设置默认适配器
$adapter = AdapterFactory::openai('sk-xxx');
Ai::setDefaultAdapter($adapter);

// 发送消息
$response = Ai::chat('你好，世界！');
echo $response->content();

// 流式响应
foreach (Ai::stream('讲一个故事') as $chunk) {
    echo $chunk;
}
```

### 4. 多模型分工代理（总工/分析员/执行员）

```php
use Kode\AiAgent\Agent\Agent;
use Kode\AiAgent\Agent\RoleAgentTeam;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

$chief = new Agent(AdapterFactory::openai('sk-chief-xxx', ['model' => 'gpt-4o']));
$analyst = new Agent(AdapterFactory::deepseek('sk-analyst-xxx', ['model' => 'deepseek-chat']));
$executor = new Agent(AdapterFactory::anthropic('sk-exec-xxx', ['model' => 'claude-3-5-sonnet']));

$team = (new RoleAgentTeam())
    ->assign('总工', $chief)
    ->assign('分析员', $analyst)
    ->assign('执行员', $executor);

$result = $team->run('建设 MCP 工具链路', [
    ['role' => '总工', 'task' => '根据目标制定技术路线：{{goal}}'],
    ['role' => '分析员', 'task' => '拆解任务并识别风险'],
    ['role' => '执行员', 'task' => '按拆解结果输出实现步骤'],
]);

foreach ($result['outputs'] as $output) {
    echo "[{$output['role']}] {$output['content']}\n";
}

// 自动路由：根据任务内容命中角色
$team->routes([
    '架构|方案|设计' => '总工',
    '分析|风险|拆解' => '分析员',
    '开发|实现|修复' => '执行员',
]);

$auto = $team->auto('请先分析需求并识别风险');
echo $auto->content();
```

### 4.1 通过 Ai 门面快速构建团队

```php
use Kode\AiAgent\Support\Facade\Ai;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

Ai::register('chief', AdapterFactory::openai('sk-chief-xxx'));
Ai::register('analyst', AdapterFactory::deepseek('sk-analyst-xxx'));
Ai::register('executor', AdapterFactory::anthropic('sk-exec-xxx'));

$team = Ai::team([
    '总工' => 'chief',
    '分析员' => 'analyst',
    '执行员' => 'executor',
]);
```

### 5. MCP Client/Server 协作

```php
use Kode\AiAgent\MCP\MCPClient;
use Kode\AiAgent\MCP\MCPServer;

$server = new MCPServer(['name' => 'demo-mcp', 'version' => '1.0.0']);
$server->registerTool('sum', '求和', fn(array $args) => ($args['a'] ?? 0) + ($args['b'] ?? 0), [
    'a' => 'number',
    'b' => 'number',
]);

$client = new MCPClient(transport: fn(array $request) => $server->handle($request));
$client->connect('mcp://local');

$tools = $client->listTools();
$value = $client->callTool('sum', ['a' => 1, 'b' => 2]);
```

## 输入校验与安全策略

- 主链路默认启用输入校验：提示词空值、长度、控制字符、常见参数范围会在调用前校验
- `chat/stream` 会先校验消息与 options，再进入适配器请求阶段
- 基础 URL 采用严格 HTTPS 策略：非 `https://` 地址会直接抛出配置异常
- 响应输出统一使用 `kode/tools` 的 Message 结构，便于业务层一致处理

## 支持的平台

| 平台 | 别名 | 适配器 | 认证方式 | 默认模型 |
|------|------|--------|---------|---------|
| OpenAI | - | `OpenAiAdapter` | API Key | gpt-4o |
| Anthropic | claude | `AnthropicAdapter` | API Key | claude-3-5-sonnet |
| DeepSeek | - | `DeepSeekAdapter` | API Key | deepseek-chat |
| 阿里云 | qwen, tongyi | `AliyunAdapter` | API Key / AppKey+AppSecret | qwen-turbo |
| Google | gemini | `GeminiAdapter` | API Key | gemini-2.0-flash |
| 百度 | wenxin, ernie | `BaiduAdapter` | API Key + Secret Key | completions_pro |
| 腾讯 | hunyuan | `TencentAdapter` | SecretId + SecretKey | hunyuan-lite |
| 讯飞 | spark, xinghuo | `XunfeiAdapter` | AppId + API Key + API Secret | generalv3.5 |

```php
// 检查平台支持
AdapterFactory::supports('openai');    // true
AdapterFactory::supports('claude');    // true (别名)
AdapterFactory::supports('unknown');   // false

// 获取所有支持的平台
$platforms = AdapterFactory::supported();
// ['openai', 'anthropic', 'claude', 'deepseek', 'aliyun', 'qwen', 'tongyi', 
//  'gemini', 'google', 'baidu', 'wenxin', 'ernie', 'tencent', 'hunyuan', 
//  'xunfei', 'spark', 'xinghuo']
```

### 国内平台认证配置

```php
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

// 百度文心一言（需要 API Key + Secret Key 获取 Access Token）
$adapter = AdapterFactory::baidu('your-api-key', 'your-secret-key', [
    'model' => 'completions_pro',
]);

// 或直接使用 Access Token
$adapter = AdapterFactory::create('baidu', [
    'access_token' => 'your-access-token',
    'model' => 'completions_pro',
]);

// 腾讯混元（使用 TC3-HMAC-SHA256 签名）
$adapter = AdapterFactory::tencent('your-secret-id', 'your-secret-key', [
    'model' => 'hunyuan-lite',
    'region' => 'ap-guangzhou',
]);

// 讯飞星火（三元组认证：AppId + API Key + API Secret）
$adapter = AdapterFactory::xunfei('your-app-id', 'your-api-key', 'your-api-secret', [
    'model' => 'generalv3.5',
]);

// 阿里云通义千问（支持两种认证方式）
// 方式1: API Key
$adapter = AdapterFactory::aliyun('your-api-key');

// 方式2: AppKey + AppSecret（签名认证）
$adapter = AdapterFactory::create('aliyun', [
    'app_key' => 'your-app-key',
    'app_secret' => 'your-app-secret',
    'model' => 'qwen-turbo',
]);
```

## API Key 管理

### 单 Key 模式

```php
use Kode\AiAgent\Domain\ValueObject\ApiKey;

$key = ApiKey::fromEnv('OPENAI_API_KEY');
// 或
$key = ApiKey::fromString('sk-1234567890abcdefghijklmnop');

echo $key->value();    // sk-1234567890abcdefghijklmnop
echo $key->masked();   // sk-1...mnop
echo $key->isValid();  // true
```

### 双 Key 模式（主备）

```php
$key = ApiKey::dual('sk-primary-xxx', 'sk-secondary-xxx');

echo $key->current();   // sk-primary-xxx (主 Key)
echo $key->strategy();  // failover

// 主 Key 失败时切换
$failed = $key->failover();
echo $failed->current(); // sk-secondary-xxx
```

### 多 Key 轮换模式

```php
$key = ApiKey::rotating([
    'sk-key-one-xxx',
    'sk-key-two-xxx',
    'sk-key-three-xxx',
], 'round_robin');

echo $key->current();   // sk-key-one-xxx
echo $key->next();      // sk-key-two-xxx
echo $key->count();     // 3

// 轮换到下一个
$rotated = $key->rotate();
echo $rotated->current(); // sk-key-two-xxx
```


### AppKey + AppSecret 模式

适用于阿里云、百度、腾讯云等需要双凭证的平台：

```php
// 创建 AppKey + AppSecret 凭证
$key = ApiKey::appSecret('app-key-xxx', 'app-secret-xxx', [
    'region' => 'cn-hangzhou',
    'account_id' => '123456',
]);

echo $key->appKey();        // app-key-xxx
echo $key->secret();     // app-secret-xxx
echo $key->extra('region'); // cn-hangzhou

// 检查模式
$key->isAppSecretMode();    // true

// 生成签名
$signature = $key->sign('POST', '/v1/chat', ['query' => 'hello']);

// 生成带签名的请求头
$headers = $key->signedHeaders('POST', '/v1/chat', ['query' => 'hello']);
// [
//     'X-App-Key' => 'app-key-xxx',
//     'X-Timestamp' => '1709512800',
//     'X-Nonce' => 'abc123...',
//     'X-Signature' => 'hmac-sha256...',
// ]

// 获取脱敏凭证信息
$masked = $key->maskedCredentials();
// ['app_key' => 'app-...-xxx', 'app_secret' => 'app-...-xxx']
```

### 从配置创建

```php
// 单 Key
$key = ApiKey::fromArray([
    'api_key' => 'sk-xxx',
]);

// 多 Key 轮换
$key = ApiKey::fromArray([
    'keys' => ['sk-1', 'sk-2', 'sk-3'],
    'strategy' => 'round_robin',  // 或 'random', 'failover'
]);

// AppKey + AppSecret（阿里云、百度等）
$key = ApiKey::fromArray([
    'app_key' => 'your-app-key',
    'app_secret' => 'your-app-secret',
    'extra' => [
        'region' => 'cn-hangzhou',
        'account_id' => '123456',
    ],
]);
```

## 向量数据库 (Store 组件)

### 内存向量存储（用于测试和简单场景）

```php
use Kode\AiAgent\Store\MemoryVectorStore;

// 创建内存向量存储
$store = new MemoryVectorStore(dimension: 1536);

// 插入向量
$store->upsert('doc-1', [0.1, 0.2, 0.3], ['title' => '文档1']);
$store->upsert('doc-2', [0.4, 0.5, 0.6], ['title' => '文档2']);

// 批量插入
$store->upsertBatch([
    ['id' => 'doc-3', 'vector' => [0.7, 0.8, 0.9], 'metadata' => ['title' => '文档3']],
    ['id' => 'doc-4', 'vector' => [1.0, 1.1, 1.2], 'metadata' => ['title' => '文档4']],
]);

// 相似度搜索（余弦相似度）
$results = $store->search([0.1, 0.2, 0.3], limit: 5, filters: []);
// [
//     ['id' => 'doc-1', 'score' => 1.0, 'metadata' => ['title' => '文档1'],
//     ['id' => 'doc-2', 'score' => 0.95, 'metadata' => ['title' => '文档2'],
// ]

// 获取向量
$doc = $store->get('doc-1');

// 删除向量
$store->delete('doc-1');

// 获取向量数量
$count = $store->count();

// 清空所有向量
$store->clear();
```

## MCP (模型上下文协议)

### MCP 服务器

```php
use Kode\AiAgent\MCP\MCPServer;

// 创建 MCP 服务器
$server = new MCPServer([
    'name' => 'my-mcp-server',
    'version' => '1.0.0',
]);

// 注册工具
$server->registerTool(
    name: 'calculator',
    description: '执行数学计算',
    handler: function (array $args) {
        $a = $args['a'] ?? 0;
        $b = $args['b'] ?? 0;
        return $a + $b;
    },
    parameters: ['a' => 'number', 'b' => 'number']
);

// 注册资源
$server->registerResource(
    uri: 'file:///data/config.json',
    provider: fn() => file_get_contents('config.json'),
    mimeType: 'application/json'
);

// 处理 JSON-RPC 请求
$response = $server->handle([
    'jsonrpc' => '2.0',
    'method' => 'tools/call',
    'params' => ['name' => 'calculator', 'arguments' => ['a' => 1, 'b' => 2],
    'id' => 1,
]);
```

## 工具调用

### 注册工具

```php
use Kode\AiAgent\Agent\Agent;

$agent = new Agent($adapter, [
    'system_prompt' => '你是一个有用的助手',
    'max_tool_calls' => 5,
]);

// 注册工具
$agent->registerTool('calculator', '执行数学计算', function (int $a, int $b, string $op = 'add'): int|float {
    return match($op) {
        'add' => $a + $b,
        'sub' => $a - $b,
        'mul' => $a * $b,
        'div' => $a / $b,
    };
});

// 发送消息（自动处理工具调用）
$response = $agent->chat('计算 10 + 5');
echo $response->content();
```

### 使用注解注册工具

```php
use Kode\AiAgent\Attribute\Tool;
use Kode\AiAgent\Agent\Agent;

class MyTools
{
    #[Tool(name: 'weather', description: '获取天气信息')]
    public function getWeather(string $city): string
    {
        return "{$city}今天晴，温度 25°C";
    }
    
    #[Tool(name: 'search', description: '搜索网络')]
    public function search(string $query): array
    {
        return ['results' => ["关于 {$query} 的结果..."];
    }
}

// 从类自动注册
$agent = Agent::fromClass(new MyTools(), $adapter);
```

## 对话管理

```php
use Kode\AiAgent\Chat\ChatSession;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

$adapter = AdapterFactory::openai('sk-xxx');
$chat = new ChatSession($adapter, '你是一个有用的助手');

// 发送消息
$response = $chat->send('你好！');
echo $response->content();

// 继续对话（自动维护上下文）
$response = $chat->send('请继续');
echo $response->content();

// 流式对话
foreach ($chat->stream('讲个故事') as $chunk) {
    echo $chunk;
    flush();
}

// 查看对话历史
$messages = $chat->messages();
$count = $chat->count();

// 导出对话
$history = $chat->export();

// 清空历史
$chat->clear();
```

## 多模态功能

Kode AI Agent 提供完整的多模态能力支持，包括文本生成图像、文本生成视频、数字人视频生成等多种功能。

### 架构概述

多模态功能采用统一的架构设计：

- **能力发现**: 通过 `MultimodalCapability` 枚举定义和发现平台支持的能力
- **统一接口**: `MultimodalInterface` 整合图像、视频、数字人等所有能力
- **服务层**: `MultimodalService` 提供高级服务功能
- **门面调用**: `Multimodal` 门面类提供简洁的静态调用接口
- **辅助函数**: 提供 `ai_generate_image()`、`ai_generate_video()` 等快速方法

### 核心组件

| 组件 | 类名 | 说明 |
|------|------|------|
| 能力枚举 | `MultimodalCapability` | 定义平台支持的所有多模态能力 |
| 适配器接口 | `MultimodalInterface` | 统一的多模态操作接口 |
| 抽象适配器 | `AbstractMultimodalAdapter` | 适配器基类，提供通用实现 |
| 服务类 | `MultimodalService` | 高级服务封装 |
| 响应模型 | `ImageResponse`/`VideoResponse`/`AvatarResponse` | 统一响应格式 |
| 文件上传器 | `FileUploaderInterface` | 媒体文件上传接口 |
| 本地上传器 | `LocalFileUploader` | 本地文件系统上传实现 |

### 快速开始

#### 1. 创建自定义多模态适配器

首先，创建一个继承自 `AbstractMultimodalAdapter` 的适配器：

```php
<?php

declare(strict_types=1);

namespace App\Adapter;

use Kode\AiAgent\Domain\Model\{AvatarResponse, ImageResponse, VideoResponse};
use Kode\AiAgent\Domain\ValueObject\{MediaFile, MultimodalCapability};
use Kode\AiAgent\Infrastructure\Adapter\AbstractMultimodalAdapter;
use Kode\HttpClient\HttpClient;

final class MyMultimodalAdapter extends AbstractMultimodalAdapter
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly array $config = [],
    ) {
        parent::__construct();
    }

    protected function initializeCapabilities(): void
    {
        $this->addCapabilities([
            MultimodalCapability::TEXT_TO_IMAGE,
            MultimodalCapability::IMAGE_EDIT,
            MultimodalCapability::TEXT_TO_VIDEO,
            MultimodalCapability::AVATAR_GENERATION,
            MultimodalCapability::AVATAR_LIST,
            MultimodalCapability::VOICE_LIST,
            MultimodalCapability::ASYNC_GENERATION,
            MultimodalCapability::PROGRESS_TRACKING,
        ]);
    }

    public function name(): string
    {
        return 'my-multimodal-platform';
    }

    public function generateImage(string $prompt, array $options = []): ImageResponse
    {
        $startTime = microtime(true);
        
        $this->ensureCapability(MultimodalCapability::TEXT_TO_IMAGE);
        
        $response = $this->httpClient->post('https://api.example.com/v1/images/generations', [
            'json' => [
                'prompt' => $prompt,
                'model' => $options['model'] ?? 'image-model-v1',
                'size' => $options['size'] ?? '1024x1024',
            ],
        ]);
        
        $duration = microtime(true) - $startTime;
        
        return new ImageResponse(
            image: $response['data'][0]['url'] ?? '',
            duration: $duration,
            model: $options['model'] ?? 'image-model-v1',
        );
    }

    public function generateVideo(string $prompt, array $options = []): VideoResponse
    {
        $startTime = microtime(true);
        
        $this->ensureCapability(MultimodalCapability::TEXT_TO_VIDEO);
        
        $response = $this->httpClient->post('https://api.example.com/v1/videos/generations', [
            'json' => [
                'prompt' => $prompt,
                'model' => $options['model'] ?? 'video-model-v1',
                'duration' => $options['duration'] ?? 5,
            ],
        ]);
        
        $duration = microtime(true) - $startTime;
        
        return new VideoResponse(
            video: $response['data'][0]['url'] ?? '',
            videoDuration: $options['duration'] ?? 5,
            duration: $duration,
            model: $options['model'] ?? 'video-model-v1',
        );
    }

    public function generateAvatarVideo(string $text, array $options = []): AvatarResponse
    {
        $startTime = microtime(true);
        
        $this->ensureCapability(MultimodalCapability::AVATAR_GENERATION);
        
        $response = $this->httpClient->post('https://api.example.com/v1/avatars/generate', [
            'json' => [
                'text' => $text,
                'avatar_id' => $options['avatar_id'] ?? 'default-female',
                'voice_id' => $options['voice_id'] ?? 'voice-female-zh',
            ],
        ]);
        
        $duration = microtime(true) - $startTime;
        
        return $this->createAvatarResponse(
            videoUrl: $response['data']['video_url'] ?? '',
            avatarId: $options['avatar_id'] ?? 'default-female',
            voiceId: $options['voice_id'] ?? 'voice-female-zh',
            text: $text,
            videoDuration: $response['data']['duration'] ?? 30.0,
            duration: $duration,
            model: 'avatar-model-v1'
        );
    }
}
```

#### 2. 初始化多模态服务

```php
use Kode\AiAgent\Support\Facade\Multimodal;
use Kode\AiAgent\Infrastructure\Persistence\LocalFileUploader;
use App\Adapter\MyMultimodalAdapter;
use Kode\HttpClient\HttpClient;

// 1. 创建文件上传器
$uploader = new LocalFileUploader(
    uploadDir: __DIR__ . '/uploads',
    baseUrl: 'https://example.com/uploads'
);

// 2. 设置文件上传器
Multimodal::setFileUploader($uploader);

// 3. 创建多模态适配器
$httpClient = new HttpClient();
$adapter = new MyMultimodalAdapter($httpClient, [
    'api_key' => 'your-api-key',
]);

// 4. 创建并设置多模态服务
$service = Multimodal::createService($adapter);
Multimodal::setDefaultService($service);
```

### 使用门面类

#### 文本生成图像

```php
use Kode\AiAgent\Support\Facade\Multimodal;

// 检查平台是否支持图像生成
if (Multimodal::supports(\Kode\AiAgent\Domain\ValueObject\MultimodalCapability::TEXT_TO_IMAGE)) {
    // 生成图像
    $response = Multimodal::generateImage('一只可爱的猫咪在花园里玩耍', [
        'style' => 'photorealistic',
        'size' => '1024x1024',
        'quality' => 'high',
    ]);

    // 获取图像 URL
    echo $response->image();
    
    // 转换为数组
    print_r($response->toArray());
}
```

#### 图像编辑

```php
// 编辑现有图像
$response = Multimodal::editImage(
    imagePath: '/path/to/original.jpg',
    prompt: '把背景改成海滩',
    options: [
        'mask' => '/path/to/mask.png',
    ]
);

echo $response->image();
```

#### 图像变体生成

```php
// 生成图像变体
$response = Multimodal::generateImageVariation(
    imagePath: '/path/to/original.jpg',
    options: [
        'variations' => 3,
        'strength' => 0.7,
    ]
);

echo $response->image();
```

#### 文本生成视频

```php
// 检查平台是否支持视频生成
if (Multimodal::supports(\Kode\AiAgent\Domain\ValueObject\MultimodalCapability::TEXT_TO_VIDEO)) {
    // 生成视频
    $response = Multimodal::generateVideo('一只可爱的猫咪在花园里追逐蝴蝶', [
        'duration' => 5,
        'resolution' => '1080p',
        'fps' => 30,
    ]);

    // 获取视频 URL
    echo $response->video();
    
    // 获取视频时长
    echo $response->videoDuration();
}
```

#### 图像生成视频

```php
// 从图像生成视频
$response = Multimodal::imageToVideo(
    imagePath: '/path/to/image.jpg',
    prompt: '让这个场景动起来',
    options: [
        'duration' => 3,
        'motion' => 'medium',
    ]
);

echo $response->video();
```

#### 获取平台能力

```php
// 获取平台支持的所有能力
$capabilities = Multimodal::capabilities();
foreach ($capabilities as $capability) {
    echo "能力: {$capability->label()}\n";
    echo "描述: {$capability->description()}\n";
}

// 检查特定能力
$canGenerateImage = Multimodal::supports(
    \Kode\AiAgent\Domain\ValueObject\MultimodalCapability::TEXT_TO_IMAGE
);

// 按类别检查能力
$capability = \Kode\AiAgent\Domain\ValueObject\MultimodalCapability::TEXT_TO_IMAGE;
if ($capability->isImage()) {
    echo "这是图像相关能力\n";
}
if ($capability->isVideo()) {
    echo "这是视频相关能力\n";
}
if ($capability->isAvatar()) {
    echo "这是数字人相关能力\n";
}

// 获取平台名称
echo Multimodal::platformName();
```

### 使用辅助函数

Kode AI Agent 提供了便捷的辅助函数，让调用更加简单：

```php
// 智能多模态生成（自动选择能力）
$result = ai_multimodal_generate('一只可爱的猫咪', [
    'output_type' => 'image',  // 或 'video', 'avatar'
]);

// 文本生成图像
$imageResponse = ai_generate_image('一只可爱的猫咪');
echo $imageResponse->image();

// 文本生成视频
$videoResponse = ai_generate_video('一只可爱的猫咪在玩耍');
echo $videoResponse->video();

// 生成数字人
$avatarResponse = ai_generate_avatar('大家好，欢迎使用！');
echo $avatarResponse->video();

// 获取数字人列表
$avatars = ai_list_avatars();
print_r($avatars);

// 获取声音列表
$voices = ai_list_voices();
print_r($voices);

// 获取任务进度
$progress = ai_get_progress('task-123');
echo $progress->status();

// 格式化文件大小
echo ai_format_file_size(1048576);  // "1.00 MB"

// 验证媒体文件
if (ai_validate_media_file('/path/to/video.mp4', 'video', 104857600)) {
    echo "文件有效\n";
}
```

### 数字人功能

数字人功能已整合到多模态架构中，通过 `MultimodalService` 或 `Multimodal` 门面类统一调用。

#### 方式1：从文本生成数字人

```php
use Kode\AiAgent\Support\Facade\Multimodal;

// 使用预设数字人和声音生成视频
$response = Multimodal::generateAvatar('大家好，欢迎使用数字人功能！', [
    'avatar_id' => 'default-female',
    'voice_id' => 'voice-female-zh',
    'language' => 'zh-CN',
    'resolution' => '1080p',
]);

// 获取视频 URL
echo $response->video();

// 获取下载提示
echo Multimodal::getDownloadPrompt($response);
```

#### 方式2：使用自定义视频

```php
// 上传您自己的视频作为数字人
$response = Multimodal::generateAvatarWithCustomVideo(
    text: '这是使用自定义视频的数字人',
    videoPath: '/path/to/your/video.mp4',
    videoFileName: 'my-video.mp4',
    options: [
        'voice_id' => 'voice-male-zh',
        'language' => 'zh-CN',
    ]
);

echo $response->video();
```

#### 方式3：使用自定义音频

```php
// 上传您自己的音频
$response = Multimodal::generateAvatarWithCustomAudio(
    audioPath: '/path/to/your/audio.mp3',
    audioFileName: 'my-audio.mp3',
    options: [
        'avatar_id' => 'default-female',
    ]
);

echo $response->video();
```

#### 方式4：从 HTTP 请求上传

```php
// 处理表单上传的视频
if (isset($_FILES['video'])) {
    $response = Multimodal::generateAvatarFromRequestVideo(
        text: $_POST['text'] ?? '',
        fileData: $_FILES['video'],
        options: [
            'voice_id' => 'voice-female-zh',
        ]
    );
    
    // 显示结果
    echo "视频生成成功！<br>";
    echo "<video src='{$response->video()}' controls></video><br>";
    echo Multimodal::getDownloadPrompt($response);
}

// 处理表单上传的音频
if (isset($_FILES['audio'])) {
    $response = Multimodal::generateAvatarFromRequestAudio(
        fileData: $_FILES['audio'],
        options: [
            'avatar_id' => 'default-female',
        ]
    );
}
```

#### 获取数字人和声音列表

```php
// 获取可用数字人列表
$avatars = Multimodal::listAvatars([
    'page' => 1,
    'page_size' => 20,
    'category' => 'business',
    'gender' => 'female',
]);

foreach ($avatars as $avatar) {
    echo "ID: {$avatar['id']}, 名称: {$avatar['name']}\n";
}

// 获取可用声音列表
$voices = Multimodal::listVoices([
    'page' => 1,
    'page_size' => 20,
    'language' => 'zh-CN',
    'gender' => 'female',
]);

foreach ($voices as $voice) {
    echo "ID: {$voice['id']}, 名称: {$voice['name']}\n";
}
```

#### 异步生成和进度跟踪

```php
// 异步生成数字人视频
$taskId = Multimodal::generateAsync('这是一个异步生成的视频', [
    'avatar_id' => 'default-female',
    'voice_id' => 'voice-female-zh',
    'callback_url' => 'https://your-site.com/webhook',
]);

echo "任务已创建，任务ID: {$taskId}\n";

// 查询任务进度
$progress = Multimodal::getProgress($taskId);

echo "状态: {$progress->status}\n";
echo "进度: {$progress->progress}%\n";
echo "消息: {$progress->message}\n";
echo "耗时: " . round($progress->updatedAt - $progress->createdAt, 2) . "秒\n";

// 检查任务状态
if ($progress->isCompleted()) {
    echo "任务完成！\n";
    $videoUrl = $progress->data['video_url'] ?? '';
    echo "视频地址: {$videoUrl}\n";
} elseif ($progress->isFailed()) {
    echo "任务失败: {$progress->message}\n";
} elseif ($progress->isGenerating()) {
    echo "正在生成中，请稍候...\n";
}

// 轮询直到任务完成
while (!$progress->isTerminal()) {
    sleep(2);
    $progress = Multimodal::getProgress($taskId);
    echo "当前进度: {$progress->progress}%\n";
}
```

#### 文件上传器

```php
use Kode\AiAgent\Infrastructure\Persistence\LocalFileUploader;
use Kode\AiAgent\Domain\ValueObject\MediaFile;
use Kode\AiAgent\Support\Facade\Multimodal;

// 创建上传器
$uploader = new LocalFileUploader(
    uploadDir: __DIR__ . '/uploads',
    baseUrl: 'https://example.com/uploads'
);

// 设置到 Multimodal 门面
Multimodal::setFileUploader($uploader);

// 上传文件
$mediaFile = $uploader->upload(
    filePath: '/path/to/source/video.mp4',
    fileName: 'my-video.mp4',
    type: MediaFile::TYPE_VIDEO
);

// 获取文件信息
echo "文件名: {$mediaFile->name}\n";
echo "路径: {$mediaFile->path}\n";
echo "大小: {$mediaFile->size} bytes\n";
echo "MIME类型: {$mediaFile->mimeType}\n";
echo "类型: {$mediaFile->type}\n";

// 检查文件类型
if ($mediaFile->isVideo()) {
    echo "这是一个视频文件\n";
}

if ($mediaFile->isAudio()) {
    echo "这是一个音频文件\n";
}

// 获取文件访问 URL
$url = Multimodal::getFileUrl($mediaFile);
echo "访问地址: {$url}\n";

// 检查文件是否存在
if ($uploader->exists($mediaFile)) {
    echo "文件存在\n";
}

// 删除文件
$uploader->delete($mediaFile);

// 从 $_FILES 上传
if (isset($_FILES['file'])) {
    $mediaFile = $uploader->uploadFromRequest(
        fileData: $_FILES['file'],
        type: MediaFile::TYPE_VIDEO
    );
}
```

### 支持的文件类型和大小限制

| 类型 | 支持的格式 | 最大大小 |
|------|-----------|---------|
| 视频 | MP4, WebM, QuickTime, AVI | 500 MB |
| 音频 | MP3, WAV, OGG, WebM, AAC | 50 MB |
| 图像 | JPEG, PNG, WebP, GIF | 10 MB |

### 异常处理

```php
use Kode\AiAgent\Exception\FileUploadException;
use Kode\AiAgent\Exception\InvalidInputException;
use Kode\AiAgent\Exception\PlatformException;

try {
    $response = $avatarService->generateFromText('你好', []);
} catch (InvalidInputException $e) {
    echo "输入错误: " . $e->getMessage();
} catch (FileUploadException $e) {
    echo "上传错误: " . $e->getMessage();
    echo "错误码: " . $e->errorCode();
    echo "上下文: " . json_encode($e->context());
    
    // 处理特定错误
    if ($e->errorCode() === FileUploadException::CODE_FILE_TOO_LARGE) {
        echo "文件太大了，请上传较小的文件";
    } elseif ($e->errorCode() === FileUploadException::CODE_INVALID_TYPE) {
        echo "不支持的文件格式";
    }
} catch (PlatformException $e) {
    echo "平台错误: " . $e->getMessage();
}
```

### 下载提示信息

系统会自动生成友好的下载提示，引导用户保存生成的视频：

```php
$prompt = $avatarService->getDownloadPrompt($response);
echo $prompt;
```

输出示例：
```
🎬 您的数字人视频已生成成功！

📦 视频信息：
   • 视频链接: https://example.com/video.mp4
   • 数字人ID: default-female
   • 声音ID: voice-female-zh
   • 视频时长: 30秒

💡 重要提示：
   1. 请点击上方链接下载视频
   2. 建议使用浏览器右键"另存为"保存视频
   3. 视频链接有有效期，请尽快下载
   4. 如需再次生成，请重新提交请求

如有问题，请联系技术支持。
```

### 响应格式

```php
// AvatarResponse 对象
$response = $avatarService->generateFromText('你好', []);

// 访问响应属性
echo $response->video();           // 视频 URL
echo $response->avatarId();        // 数字人 ID
echo $response->voiceId();         // 声音 ID
echo $response->text();            // 输入文本
echo $response->videoDuration();   // 视频时长（秒）
echo $response->duration();        // 生成耗时（秒）
echo $response->code();            // 状态码
echo $response->msg();             // 消息
echo $response->requestId();       // 请求 ID
echo $response->model();           // 模型名称

// 检查是否成功
if ($response->isSuccess()) {
    echo "生成成功！";
}

// 转换为数组
$array = $response->toArray();

// 转换为 JSON
$json = $response->toJson();

// 直接输出视频 URL
echo $response;
```

## 流式响应

### 同步流式

```php
use Kode\AiAgent\Domain\Model\Prompt;

foreach ($adapter->stream(new Prompt('讲一个故事')) as $chunk) {
    echo $chunk;
    flush();
}
```

### SSE 流式响应

```php
use Kode\AiAgent\SSE\SSEEmitter;

$sse = new SSEEmitter();

foreach ($adapter->stream(new Prompt('讲一个故事')) as $chunk) {
    $sse->message($chunk);
}

$sse->close();
```

## 管道中间件

```php
use Kode\AiAgent\Application\Service\AgentService;

$service = new AgentService($adapter);

// 日志中间件
$service->pipe(function ($prompt, $next) {
    $start = microtime(true);
    echo "[LOG] 开始处理\n";
    
    $response = $next($prompt);
    
    $duration = microtime(true) - $start;
    echo "[LOG] 完成，耗时: {$duration}s\n";
    
    return $response;
});

// 缓存中间件
$service->pipe(function ($prompt, $next) use ($cache) {
    $key = md5($prompt->text());
    
    if ($cached = $cache->get($key)) {
        return $cached;
    }
    
    $response = $next($prompt);
    $cache->set($key, $response, 3600);
    
    return $response;
});

$response = $service->chat('你好');
```

## 注解处理器

```php
use Kode\AiAgent\Pipeline\AnnotationProcessor;
use Kode\AiAgent\Attribute\{Cache, RateLimit, Retry};

class MyService
{
    #[Cache(ttl: 3600, key: 'chat_{hash}')]
    #[RateLimit(requests: 60, per: 'minute')]
    #[Retry(maxAttempts: 3, delay: 1000)]
    public function chat(string $message): ResponseInterface
    {
        // 自动缓存、限流、重试
    }
}

$processor = new AnnotationProcessor($cache);
$response = $processor->process($prompt, $adapter, fn($p) => $adapter->send($p));
```

## 输入验证

```php
use Kode\AiAgent\Support\Validator\InputValidator;

$validator = new InputValidator();

// 验证提示词
$prompt = $validator->validatePrompt($userInput, [
    'max_length' => 100000,
    'min_length' => 1,
]);

// 验证消息历史
$messages = $validator->validateMessages($messages);

// 验证工具参数
$args = $validator->validateToolCall('calculator', ['a' => 1, 'b' => 2]);

// 验证配置选项
$options = $validator->validateOptions([
    'temperature' => 0.7,
    'max_tokens' => 1000,
]);

// 验证 API Key 格式
$isValid = $validator->isValidApiKey('sk-xxx');
```

## 辅助函数

### 通用辅助函数

```php
// Token 估算
$tokens = ai_token_count('你好，世界');  // 约 3 tokens

// 日志脱敏
$safe = ai_sanitize_log([
    'api_key' => 'sk-xxx',
    'message' => 'hello',
]);
// ['api_key' => '***REDACTED***', 'message' => 'hello']

// API Key 脱敏显示
echo ai_mask_key('sk-1234567890abcdef');  // sk-1...cdef

// 智能消息裁剪
$messages = ai_truncate_messages($messages, 4000);

// 格式化持续时间
echo ai_format_duration(0.001234);  // 1.23 ms

// 格式化 Token 数量
echo ai_format_tokens(1500);  // 1.5K
```

### 多模态辅助函数

```php
// 智能多模态生成（自动选择能力）
$result = ai_multimodal_generate('一只可爱的猫咪', [
    'output_type' => 'image',  // 或 'video', 'avatar'
]);

// 文本生成图像
$imageResponse = ai_generate_image('一只可爱的猫咪');
echo $imageResponse->image();

// 文本生成视频
$videoResponse = ai_generate_video('一只可爱的猫咪在玩耍');
echo $videoResponse->video();

// 生成数字人
$avatarResponse = ai_generate_avatar('大家好，欢迎使用！');
echo $avatarResponse->video();

// 获取数字人列表
$avatars = ai_list_avatars();
print_r($avatars);

// 获取声音列表
$voices = ai_list_voices();
print_r($voices);

// 获取任务进度
$progress = ai_get_progress('task-123');
echo $progress->status();

// 格式化文件大小
echo ai_format_file_size(1048576);  // "1.00 MB"

// 验证媒体文件
if (ai_validate_media_file('/path/to/video.mp4', 'video', 104857600)) {
    echo "文件有效\n";
}
```

## 异常处理

```php
use Kode\AiAgent\Exception\{
    AuthenticationException,
    ConfigurationException,
    PlatformException,
    RateLimitException,
    TimeoutException,
    ToolExecutionException,
    ConnectionException,
    InvalidResponseException
};

try {
    $response = $agent->chat('你好');
} catch (AuthenticationException $e) {
    echo "认证失败: " . $e->getMessage();
} catch (ConnectionException $e) {
    echo "连接失败: " . $e->getMessage();
} catch (InvalidResponseException $e) {
    echo "响应解析失败: " . $e->getMessage();
} catch (RateLimitException $e) {
    $retryAfter = $e->context()['retry_after'] ?? 60;
    echo "频率限制，{$retryAfter}秒后重试";
} catch (TimeoutException $e) {
    echo "请求超时";
} catch (ToolExecutionException $e) {
    echo "工具执行失败: " . $e->getMessage();
    // 错误码: 4001=不存在, 4002=执行失败, 4003=参数无效, 4004=超时
} catch (PlatformException $e) {
    echo "平台错误: " . $e->getMessage();
}
```

## 扩展适配器

```php
use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Infrastructure\Adapter\StreamHelper;

final readonly class MyAdapter implements AdapterInterface
{
    use StreamHelper;
    
    public function __construct(
        private \Kode\HttpClient\HttpClient $client,
        private array $config,
    ) {}
    
    #[\NoDiscard]
    public function send(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        // 实现同步请求
    }
    
    #[\NoDiscard]
    public function stream(PromptInterface $prompt, array $options = []): \Generator
    {
        // 实现流式响应
        // 可使用 $this->readLine(), $this->parseSseLine() 等方法
    }
    
    public function name(): string
    {
        return 'my-platform';
    }
}

// 注册自定义适配器
AdapterFactory::register('my-platform', MyAdapter::class);
```

## 配置选项

```php
$config = [
    'api_key' => 'sk-xxx',              // API Key
    'model' => 'gpt-4o',                // 模型名称
    'base_url' => 'https://...',        // 自定义端点
    'timeout' => 30,                    // 请求超时(秒)
    'retries' => 3,                     // 重试次数
];
```

## 测试

```bash
# 安装依赖
composer install

# 运行测试
composer test

# 代码静态分析
composer analyse
```

## 项目结构

```
src/
├── Agent/                 # Agent 核心类
├── Application/           # 应用服务
├── Attribute/             # 注解定义
├── Chat/                  # 对话管理
├── Domain/                # 领域层
│   ├── Contract/          # 接口契约
│   ├── Model/             # 领域模型
│   └── ValueObject/       # 值对象
├── Exception/             # 异常类
├── Infrastructure/        # 基础设施层
│   ├── Adapter/           # 平台适配器
│   └── Client/            # HTTP 客户端
├── MCP/                   # MCP 协议实现
├── Pipeline/              # 管道处理
├── SSE/                   # SSE 流式响应
├── Store/                 # 向量数据库
├── Support/               # 辅助工具
│   ├── Builder/           # 构建器
│   ├── Facade/            # 门面
│   ├── Helper/            # 辅助函数
│   └── Validator/         # 验证器
└── Tool/                  # 工具注册表
```

## 环境要求

- PHP 8.2+
- Composer 2.0+

## 许可证

Apache-2.0


## 联系方式

- 问题反馈: https://github.com/kodephp/ai-agent/issues
- 维护者: Kode Team <382601296@qq.com>

## 短剧生成系统 (v1.8.0+)

Kode AI Agent 提供完整的短剧生成工作流，支持一键生成短视频。

### 架构概述

```
┌─────────────────────────────────────────────────────────────────┐
│                        DramAgent                                 │
├─────────────────────────────────────────────────────────────────┤
│  1. 剧本解析 → 2. 场景拆分 → 3. 文生图 → 4. 图生视频 → 5. 视频合成  │
└─────────────────────────────────────────────────────────────────┘
```

### 核心组件

| 组件 | 类名 | 说明 |
|------|------|------|
| 短剧生成器 | `DramaGenerator` | 基础短剧生成 |
| 短剧智能体 | `DramAgent` | 支持并行处理的高级生成器 |
| 故事板 | `StoryBoard` | 剧本场景结构 |
| 场景 | `Scene` | 单个场景定义 |
| 场景视频 | `SceneVideo` | 场景视频引用 |
| 视频合成器 | `VideoComposer`/`VideoComposerV2` | 多视频合并 |

### 快速开始

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
echo "场景数量: " . count($result->scenes) . "\n";
```

### 生成流程

```php
// 1. 解析剧本
$storyBoard = $agent->parseScript('剧本内容...', [
    'scenes' => 8,
    'style' => 'cinematic',
]);

// 2. 生成场景图像（支持并行）
$scenes = $agent->generateSceneImages($storyBoard, [
    'image_size' => '1920x1080',
]);

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

### 带数字人的短剧

```php
// 生成包含数字人讲解的短剧
$result = $agent->generateWithAvatar(
    script: '今天给大家介绍一款新产品...',
    avatarOptions: [
        'avatar_id' => 'default-female',
        'voice_id' => 'voice-female-zh',
        'language' => 'zh-CN',
    ],
    dramaOptions: [
        'scenes' => 5,
        'style' => 'modern',
    ]
);
```

### 视频合成增强

```php
use Kode\AiAgent\Video\VideoComposerV2;
use Kode\AiAgent\Video\VideoMerger;

// 创建视频合成器
$composer = new VideoComposerV2(
    logger: null,
    concurrency: 4,
    config: ['output_dir' => 'var/drama/output']
);

// 合成视频
$output = $composer->compose($sceneVideos, [
    'transition' => 'fade',
    'background_music' => '/path/to/music.mp3',
    'music_volume' => 0.3,
]);

// 合并多个视频
$output = $composer->concatenate([
    '/path/to/video1.mp4',
    '/path/to/video2.mp4',
    '/path/to/video3.mp4',
]);

// 视频分段
$chunks = $composer->split('/path/to/long-video.mp4', 60);

// 添加水印
$watermarked = $composer->addWatermark(
    '/path/to/video.mp4',
    '/path/to/watermark.png',
    ['position' => '右下', 'opacity' => 0.3]
);
```

## 日志系统 (Monolog 集成)

### 日志工厂

```php
use Kode\AiAgent\Log\LoggerFactory;

// 创建文件日志
$logger = LoggerFactory::create([
    'channel' => 'ai-agent',
    'level' => 'debug',
    'path' => 'var/log/ai-agent.log',
    'env' => 'dev',
]);

// 创建控制台日志
$console = LoggerFactory::console([
    'level' => 'debug',
    'output' => 'php://stdout',
]);
```

### 日志管理器

```php
use Kode\AiAgent\Log\LogManager;

// 初始化
LogManager::init(['env' => 'dev']);

// 获取日志器
$logger = LogManager::get();

// 使用日志
LogManager::info('消息发送成功', ['message_id' => 'xxx']);
LogManager::error('请求失败', ['error' => 'timeout']);

// 获取指定频道
$videoLogger = LogManager::channel('video');
$videoLogger->info('视频处理完成');
```

### 自动脱敏

敏感信息自动脱敏：

```php
LogManager::info('API请求', [
    'api_key' => 'sk-xxx',  // 自动变为 ***REDACTED***
    'token' => 'Bearer xxx', // 自动变为 ***REDACTED***
    'message' => 'hello',    // 保持原样
]);
```

## 并行处理 (Fiber/协程)

### 异步任务

```php
use Kode\AiAgent\Async\AsyncTask;

// 创建异步任务
$task = new AsyncTask(function() {
    // 执行耗操作
    return $result;
});

$task->start();
$result = $task->getResult();
```

### Fiber 池

```php
use Kode\AiAgent\Async\FiberPool;

// 创建并发池
$pool = new FiberPool(concurrency: 10);

// 提交任务
$pool->submit(fn() => processImage($image));

// 批量执行
$pool->runAndWait();
```

### 并行执行器

```php
use Kode\AiAgent\Async\ParallelExecutor;

// 创建执行器
$executor = new ParallelExecutor(
    concurrency: 4,
    enableParallel: true
);

// 并行执行
$results = $executor->executeBatch([
    fn() => generateImage('场景1'),
    fn() => generateImage('场景2'),
    fn() => generateImage('场景3'),
    fn() => generateImage('场景4'),
]);

// Map 操作
$images = $executor->map($prompts, fn($p) => generateImage($p));
```

## 进程管理

### 进程池

```php
use Kode\AiAgent\Process\ProcessPool;

// 创建进程池
$pool = new ProcessPool(maxProcesses: 4);

// 提交进程任务
$pool->submit('ffmpeg -i input.mp4 output.mp4');

// 等待完成
$pool->runAndWait();
```

### 单个进程

```php
use Kode\AiAgent\Process\Process;

// 创建进程
$process = new Process('ffmpeg -i input.mp4 output.mp4', [
    'timeout' => 300,
]);

// 启动
$process->start();

// 等待完成
$process->wait();

// 获取输出
echo $process->getOutput();
```
