# 更新日志

本项目所有重要变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

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
