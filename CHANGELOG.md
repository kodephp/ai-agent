# 更新日志

本项目所有重要变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

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
