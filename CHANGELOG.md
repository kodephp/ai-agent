# 更新日志

本项目所有重要变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [2.22.0] - 2026-07-13

### 增强 - 漫剧导演真实合成（本地 ffmpeg）

将 `VideoComposerV3` 重写为**真正的本地合成器**（不依赖任何外部服务 / API）：

- **转场真正生效**：合成时按 `TransitionManager` 中每段之间的转场，用 ffmpeg
  `xfade` / `acrossfade` 做视频与音频交叉过渡（支持 fade / dissolve / slide_*
  / cross_wipe 等，映射到 xfade 转场名），并扣除转场重叠时长计算总时长。
- **输入归一化**：合成前统一分辨率 / 帧率 / 像素格式，并用 `ffprobe` 检测音轨、
  缺失时补静音轨，保证不同来源片段稳定拼接。
- **开场 / 结尾视频**：前后拼接（保留音轨）。
- **背景音乐**：与原音 `amix` 混音（可设音量）。
- **字幕**：`drawtext` 绘制（需配置 `subtitle_font`，否则自动跳过并记录警告）。
- **健壮性**：转场合成失败时自动回退到普通拼接；`enable_transitions=false`
  可关闭转场。

#### DramaDirector 联动

- `DramaDirector::compose()` 现在会把**每段各自的转场**逐个 `addTransition` 传给合成器，
  实现"每段不同转场"的导演式组合。

#### 其它

- `VideoComposerV3::addTransition()` 时长参数改为 `float`（xfade 支持小数秒）。
- `TransitionEffect::$duration` / `TransitionManager::addTransition()` 时长改为 `float`。
- 新增 `tests/VideoComposerV3Test.php`（3 用例：转场+音乐 / 回退 / 单段）与
  `tests/DramaDirectorIntegrationTest.php`（端到端真实合成）。

## [2.21.0] - 2026-07-13

### 新增 - AI 漫剧导演（DramaDirector）

面向"导演视角组合视频"的场景，新增 **AI 漫剧导演** 模块：将剧本拆分为分镜、逐段生成、
可单段重生成、最后合成成片。每段可绑定不同模型（ModelBinding），便于后续替换为更优模型。

#### 核心模块（src/Drama/Director/）

- `ScriptSplitter`：剧本拆分器。支持纯文本（按空行分块 + 行内指令
  `@title/@model/@provider/@bg/@bgv/@transition/@duration`）与结构化数组两种输入；
  片段可继承上一段的模型绑定。
- `DramaSegment`：分镜值对象（提示词 / 转场 / 背景图 / 背景视频 / 模型 / 时长 / 状态），
  通过 `with()` 不可变更新，便于单段调整。
- `ModelBinding`：每段模型绑定（provider + model），`toOptions()` 直接驱动统一视频网关路由。
- `DramaDirector`：`generate()` 一键流程（拆分→逐段生成→合成）、
  `regenerateSegment()` 单段重生成、`compose()` 重新合成；支持背景视频复用、供应商失败回退。
- `DramaResult`：结果值对象，含 `finalVideo`、`stats()`、`toArray()`。

#### 门面与 Helper

- 新增 `Kode\AiAgent\Support\Facade\Drama` 门面（`Drama::setGateway()` /
  `Drama::generate()` / `Drama::regenerateSegment()` / `Drama::compose()`）。
- 新增 helper：`ai_drama_director($gateway)`、`ai_drama_generate($script, $gateway, $options)`。

#### 其他

- 将 `TransitionType` 枚举抽离为独立文件 `src/Drama/TransitionType.php`（满足 PSR-4 自动加载）。
- 新增 `tests/DramaDirectorTest.php`（8 用例：拆分、生成、单段重生成、背景复用、失败回退、合成校验）。

## [2.20.0] - 2026-07-13

### 新增 - 统一视频网关（万和水岸多供应商视频中台）

面向"单 Key 多视频模型"场景，新增与 MOE 同构的**统一视频网关**，
在 Seedance、通义万相、数字人 等国内外供应商之间按能力 / 成本 / 健康度自动路由，失败时自动转移。

#### 核心模块

- `Kode\AiAgent\Domain\Contract\VideoProviderInterface` - 视频供应商统一契约
- `Kode\AiAgent\VideoGateway\VideoGateway` - 统一网关入口
- `Kode\AiAgent\VideoGateway\VideoRouter` - 视频路由器（能力过滤 + 失败自动转移）
- `Kode\AiAgent\VideoGateway\VideoExpert` - 视频专家（能力/优先级/权重/健康度）
- `Kode\AiAgent\VideoGateway\VideoPriceTable` - 视频生成价格表（成本估算/报表）
- `Kode\AiAgent\VideoGateway\Strategy\{CapabilityAware,CostAware,RoundRobin}VideoStrategy` - 路由策略
- `Kode\AiAgent\Support\Builder\VideoGatewayBuilder` - 网关构建器
- `Kode\AiAgent\Support\Facade\Video` - 静态门面

#### 新增供应商

- `Kode\AiAgent\VideoGateway\Provider\SeedanceVideoProvider` - 字节跳动 Seedance（兼容 **2.0 / 2.5**）
- `Kode\AiAgent\VideoGateway\Provider\WanxiangVideoProvider` - 阿里通义万相文/图生视频（wanx2.1）
- `Kode\AiAgent\VideoGateway\Provider\AliyunAvatarProvider` - 阿里数字人视频生成

#### 升级

- `SeedanceAdapter` / `SeedanceService` 支持 Seedance **2.5**（`seedance-2.5-pro/lite`），
  并可通过 `version`(2.0/2.5) + `tier`(pro/lite) 或显式 `model` 配置版本

#### 新增辅助函数

- `ai_video_gateway()` - 获取统一视频网关
- `ai_video_text_to_video()` - 统一文生视频
- `ai_video_image_to_video()` - 统一图生视频
- `ai_video_avatar()` - 统一数字人视频

### 测试

- 新增 `tests/VideoGatewayTest.php`（路由/失败转移/成本策略/报表）
- 新增 `tests/VideoProviderTest.php`（版本解析/能力/成本）

## [2.19.0] - 2026-07-06

### 修复 - 静态分析与架构健壮性

#### 修复 PHPStan 全部错误

- 修复 `ShortDramaTeam` 构造函数类型错误：改为显式注入 `AdapterInterface` 文本适配器与 `MultimodalInterface` 多模态适配器
- 将 `Response`、`VideoResponse`、`ImageResponse`、`AvatarResponse`、`Message`、`Prompt`、`ModelConfig` 标记为 `final readonly`，消除 `new static()` 不安全用法
- 修复 `AgentHub::pipeline()` 与 `RoleAgentTeam::pipeline()` 空阶段时 `$index` 未定义问题
- 修复 `Agent::extractToolCalls()` 中冗余的 `??` 表达式
- 修复 `ExecutionContext` 中未使用属性及浮点数严格比较问题
- 修复 `SupervisorAgent`、`AgentMemory`、`DramAgentV2` 中未读属性/常量问题
- 修复 `CircuitBreaker::notifyStateChange()` 中冗余的 `is_callable` 检查
- 修复 `AbstractMultimodalAdapter::generate()` 默认分支 PHPStan 误报
- 移除 `BaiduAdapter` 中未使用的 `TOKEN_CACHE_KEY` 常量

#### 兼容性

- 新增 `src/Support/Polyfill/NoDiscard.php`，为 PHP 8.3/8.4 提供 `#[\NoDiscard]` 属性兼容
- `composer.json` 自动加载已包含该 polyfill

#### 文档与规则

- 根据实际功能修正 `README.md`、`docs/ADVANCED_GUIDE.md`、`docs/MULTI_AGENT_TUTORIAL.md`
- 修正 `.trae/rules/ai-agent.md` 与 `.trae/skills/ai-agent-develop/SKILL.md` 中的过期示例与架构图

#### 测试

- 全量测试：`201 tests, 406 assertions` 全部通过
- 静态分析：`composer analyse`（PHPStan level 5）零错误

## [2.18.0] - 2026-07-06

### 优化 - MOE 架构对比与 Token 消耗平衡

#### 市场 MOE 架构 vs 本系统架构

| 维度 | 传统 MOE（如 Mixtral、GShard） | 本系统 MOE 网关 |
|------|------------------------------|----------------|
| 路由粒度 | 单一模型内部，对参数/层分组 | 网关层跨模型路由 |
| Key 管理 | 用户需为每个模型单独申请 Key | 后台管理各平台 Key，用户只感知一个网关 |
| Token 平衡 | 依赖模型内部稀疏激活 | 基于模型效率指数、价格、预算做跨模型均衡 |
| 适用场景 | 超大规模预训练模型 | 多平台 API 聚合、成本优化、高可用 |
| 扩展性 | 增加专家需重新训练 | 新增平台/模型只需配置 |

**结论**：本系统面向"单 Key 多模型"的企业级 API 网关场景，
比传统模型内部 MOE 更贴合多供应商、多模型、成本敏感的实际业务需求。

#### 新增模块

- `Kode\AiAgent\Token\Skill\CompressionSkillInterface` - 压缩技能接口
- `Kode\AiAgent\Token\Skill\WhitespaceNormalizeSkill` - 空白规范化技能
- `Kode\AiAgent\Token\Skill\MarkdownStripSkill` - Markdown 精简技能
- `Kode\AiAgent\Token\Skill\SynonymReplacementSkill` - 同义词替换技能
- `Kode\AiAgent\Token\Skill\CourtesyRemovalSkill` - 客套话去除技能
- `Kode\AiAgent\Token\SkillBasedCompressor` - 基于技能链的 Prompt 压缩器
- `Kode\AiAgent\Token\ModelTokenEfficiency` - 模型 Token 效率指数
- `Kode\AiAgent\Token\TokenBalancer` - 跨模型 Token 消耗平衡器
- `Kode\AiAgent\Moe\AutoCompressionMiddleware` - 自动压缩中间件
- `Kode\AiAgent\Moe\Strategy\TokenBalancedStrategy` - Token 均衡路由策略
- `Kode\AiAgent\Support\JsonParser` - PHP 8.3+ json_validate 封装

#### 新增能力

- **技能化压缩**：Prompt 压缩拆分为可插拔技能，便于按需组合与扩展
- **Token 效率归一化**：不同模型 Token 消耗按语言场景归一化，公平比较
- **Token 均衡路由**：综合考虑能力、价格、Token 效率，自动选最省模型
- **自动压缩**：MOE 网关可配置超过阈值自动压缩 Prompt，透明省钱
- **一键智能聊天**：`MoE::smartChat()` / `ai_smart_chat()` 自动压缩 + 自动路由
- **PHP 8.3 新特性**：`JsonParser` 使用 `json_validate()` 前置校验响应 JSON

#### 新增辅助函数

- `ai_smart_chat()` - 一键智能聊天
- `ai_compress_savings()` - 计算压缩节省量
- `ai_token_balance_report()` - 多模型 Token 消耗对比报告
- `ai_recommend_model()` - 推荐最省 Token 的模型

#### 测试

新增测试文件：
- `tests/SkillBasedCompressorTest.php`
- `tests/TokenBalancerTest.php`
- `tests/TokenBalancedStrategyTest.php`
- `tests/AutoCompressionMiddlewareTest.php`
- `tests/JsonParserTest.php`

#### 变更

- `composer.json` 版本号修正为 `2.18.0`
- `MoEGateway` 支持 `auto_compress` 配置
- `MoEBuilder` 支持 `autoCompress()` 链式配置
- `RoutingContext` 新增 `prompt_text` 字段，用于 Token 效率分析

## [2.17.0] - 2026-07-06

### 新增 - MOE 混合专家架构

#### 核心思想
融合业界主流的 Mixture of Experts（混合专家）思想，针对"单 Key 多模型"场景做深度适配：
- **后台**：分别申请各平台 Key，独立管理
- **用户**：只感知一个网关，自动按能力/成本/健康度选择最优专家
- **Token 消耗**：跨模型自动均衡，避免单点过载

#### 新增模块
- `Kode\AiAgent\Moe\MoEGateway` - 统一网关入口
- `Kode\AiAgent\Moe\Expert` - 专家实现
- `Kode\AiAgent\Moe\ModelRouter` - 模型路由器
- `Kode\AiAgent\Moe\RoutingContext` - 路由上下文
- `Kode\AiAgent\Moe\TokenBudget` - Token 预算控制
- `Kode\AiAgent\Moe\ModelPriceTable` - 模型价格表
- `Kode\AiAgent\Moe\Contract\ExpertInterface` - 专家接口
- `Kode\AiAgent\Moe\Contract\RouterInterface` - 路由器接口
- `Kode\AiAgent\Moe\Strategy\CapabilityAwareStrategy` - 能力感知路由
- `Kode\AiAgent\Moe\Strategy\CostAwareStrategy` - 成本感知路由
- `Kode\AiAgent\Moe\Strategy\RoundRobinStrategy` - 轮询路由
- `Kode\AiAgent\Moe\Strategy\RoutingStrategyInterface` - 策略接口
- `Kode\AiAgent\Support\Builder\MoEBuilder` - 网关构建器
- `Kode\AiAgent\Support\Facade\MoE` - 网关门面

### 新增 - Token 优化

- `Kode\AiAgent\Token\PromptCompressor` - Prompt 压缩（空白规范化、Markdown 精简、同义词替换、客套去除、Token 预算裁剪）
- `Kode\AiAgent\Token\TokenCounter` - Token 计数（单文本/批量/消息列表）
- `Kode\AiAgent\Token\MessageHistoryCompressor` - 消息历史压缩（Token 预算裁剪、滑动窗口）
- `Kode\AiAgent\Token\ResponseCache` - 响应缓存（PSR-16、命中率统计、自动成本节省）

### 新增 - 安全增强

- `Kode\AiAgent\Security\PromptInjectionDetector` - 提示词注入检测（25+ 攻击模式：角色劫持、指令覆盖、ChatML 注入、Llama2 指令注入、数据外泄、代码执行、输出劫持）
- `Kode\AiAgent\Security\InjectionReport` - 检测报告值对象
- `Kode\AiAgent\Security\PromptInjectionException` - 注入异常
- `Kode\AiAgent\Security\PiiDetector` - PII 脱敏（身份证、手机号、邮箱、银行卡、IP）

### 新增 - 弹性韧性

- `Kode\AiAgent\Resilience\CircuitBreaker` - 熔断器（closed/open/half_open 三态机）
- `Kode\AiAgent\Resilience\CircuitOpenException` - 熔断异常
- `Kode\AiAgent\Resilience\HealthChecker` - 健康检查器

### 新增 - 辅助函数

- `ai_moe()` - 获取 MOE 网关
- `ai_moe_chat()` - MOE 智能聊天
- `ai_compress_prompt()` - 压缩 Prompt
- `ai_token_estimate()` - 估算 Token
- `ai_pii_mask()` - PII 脱敏

### 测试

新增 11 个测试文件，单元测试覆盖：
- `tests/CircuitBreakerTest.php`
- `tests/ExpertTest.php`
- `tests/MessageHistoryCompressorTest.php`
- `tests/MoEGatewayTest.php`
- `tests/ModelPriceTableTest.php`
- `tests/ModelRouterTest.php`
- `tests/PiiDetectorTest.php`
- `tests/PromptCompressorTest.php`
- `tests/PromptInjectionDetectorTest.php`
- `tests/ResponseCacheTest.php`
- `tests/RoutingStrategyTest.php`
- `tests/TokenBudgetTest.php`
- `tests/TokenCounterTest.php`

### 变更

- PHP 最低版本从 `^8.2` 提升至 `^8.3`
- `README.md` 新增 MOE 架构、Token 优化、安全增强章节
- 项目描述更新以反映 MOE 架构能力

## [2.16.0] - 2026-03-04

### 新增
- 增强 SeedanceService 批量处理功能
- AI 字幕、配音旁白、视频剪辑、工作流预设
- AI 智能学习平台相关功能

## [2.15.0] - 2026-03-04

### 文档
- 完善文档，更新 Seedance 说明

## [2.14.0] - 2026-03-04

### 优化
- Seedance 优化：分辨率可配置 + 直接调用

## [2.13.0] - 2026-03-04

### 新增
- Seedance 2.0 视频生成适配器

## [2.12.0] - 2026-03-04

### 新增
- AgentHub 单 Key 多 Agent
- ScriptGenerator 剧本生成

## [2.11.0] - 2026-03-04

### 新增
- Helper 快速函数

## [2.10.0] - 2026-03-04

### 重构
- SupervisorAgent 重构使用 AgentTeamTrait

[2.17.0]: https://github.com/kodephp/ai-agent/compare/v2.16.0...v2.17.0
[2.16.0]: https://github.com/kodephp/ai-agent/compare/v2.15.0...v2.16.0
[2.15.0]: https://github.com/kodephp/ai-agent/compare/v2.14.0...v2.15.0
[2.14.0]: https://github.com/kodephp/ai-agent/compare/v2.13.0...v2.14.0
[2.13.0]: https://github.com/kodephp/ai-agent/compare/v2.12.0...v2.13.0
[2.12.0]: https://github.com/kodephp/ai-agent/compare/v2.11.0...v2.12.0
[2.11.0]: https://github.com/kodephp/ai-agent/compare/v2.10.0...v2.11.0
[2.10.0]: https://github.com/kodephp/ai-agent/releases/tag/v2.10.0
