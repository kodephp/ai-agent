<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;
use Kode\AiAgent\Moe\Contract\{ExpertInterface, RouterInterface};
use Kode\AiAgent\Moe\Strategy\TokenBalancedStrategy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MOE 网关
 *
 * 单 Key 多模型的统一入口。用户配置一个或多个平台的 Key，
 * 网关内部维护专家池，自动根据任务、能力、成本、可用性选择最优专家。
 *
 * 关键特性：
 * - 单 Key 多模型：用户只感知一个网关，实际可调用多个模型
 * - 智能路由：能力匹配 + 成本感知 + 健康检查
 * - Token 预算：自动控制单次/每日/每月消耗
 * - 故障转移：专家失败后自动标记不健康，下次请求自动跳过
 * - 统计透明：实时统计每个专家的调用情况
 *
 * @package Kode\AiAgent\Moe
 *
 * @example
 * ```php
 * // 后台管理员视角：分别申请各平台的 Key
 * $gateway = new MoEGateway();
 * $gateway->addExpert('openai', 'sk-openai-xxx', ['chat', 'vision'], priority: 10);
 * $gateway->addExpert('deepseek', 'sk-deepseek-xxx', ['chat', 'code'], priority: 20);
 * $gateway->addExpert('aliyun', 'sk-aliyun-xxx', ['chat'], priority: 30);
 *
 * // 用户视角：只需要一个"网关"，按需调用
 * $response = $gateway->chat('写一首诗', ['capability' => 'chat']);
 * ```
 */
final class MoEGateway
{
    private ModelRouter $router;
    private LoggerInterface $logger;
    private AutoCompressionMiddleware $autoCompression;

    /**
     * @param array{
     *     per_minute_tokens?: int,
     *     per_day_tokens?: int,
     *     per_month_cost?: float,
     * } $budgetConfig Token 预算配置
     * @param string $strategy 路由策略：capability_aware|cost_aware|round_robin|token_balanced
     * @param bool|array<string, mixed> $autoCompress 自动压缩配置
     *        true/false 或 [
     *            'enabled' => true,
     *            'threshold' => 1000,
     *            'target_ratio' => 0.75,
     *            'min_tokens' => 100,
     *        ]
     */
    public function __construct(
        array $budgetConfig = [],
        string $strategy = 'capability_aware',
        ?LoggerInterface $logger = null,
        bool|array $autoCompress = false,
    ) {
        $this->logger = $logger ?? new NullLogger();

        $priceTable = new ModelPriceTable();
        $budget = new TokenBudget(
            perMinute: $budgetConfig['per_minute_tokens'] ?? null,
            perDay: $budgetConfig['per_day_tokens'] ?? null,
            perMonthCost: $budgetConfig['per_month_cost'] ?? null,
        );

        $strategyInstance = match ($strategy) {
            'cost_aware' => new \Kode\AiAgent\Moe\Strategy\CostAwareStrategy($priceTable),
            'round_robin' => new \Kode\AiAgent\Moe\Strategy\RoundRobinStrategy(),
            'token_balanced' => new TokenBalancedStrategy($priceTable),
            default => new \Kode\AiAgent\Moe\Strategy\CapabilityAwareStrategy(),
        };

        $this->router = new ModelRouter($strategyInstance, $this->logger, $priceTable, $budget);
        $this->autoCompression = $this->resolveAutoCompression($autoCompress);
    }

    /**
     * 添加专家（便捷方法：通过平台名 + Key 创建）
     *
     * @param string $platform 平台标识（openai/anthropic/deepseek/aliyun/...）
     * @param string|array $apiKey 单 Key 或多 Key 数组
     * @param array<int, string> $capabilities 能力标签
     * @param string|null $model 指定模型
     * @param int $priority 优先级（数值越小越优先）
     * @param float $weight 权重
     * @param array<string, mixed> $options 适配器配置
     */
    public function addExpert(
        string $platform,
        string|array $apiKey,
        array $capabilities = ['chat'],
        ?string $model = null,
        int $priority = 100,
        float $weight = 1.0,
        array $options = [],
    ): self {
        $config = $options;
        if (is_array($apiKey)) {
            $config['api_key'] = $apiKey[0] ?? '';
            $config['keys'] = $apiKey;
        } else {
            $config['api_key'] = $apiKey;
        }

        if ($model !== null) {
            $config['model'] = $model;
        }

        $adapter = AdapterFactory::create($platform, $config);
        $expert = new Expert(
            adapter: $adapter,
            capabilities: $capabilities,
            priority: $priority,
            weight: $weight,
        );

        $this->router->registerExpert($expert);
        return $this;
    }

    /**
     * 注册一个已构造好的适配器为专家
     */
    public function addAdapter(
        AdapterInterface $adapter,
        array $capabilities = ['chat'],
        int $priority = 100,
        float $weight = 1.0,
    ): self {
        $expert = new Expert(
            adapter: $adapter,
            capabilities: $capabilities,
            priority: $priority,
            weight: $weight,
        );
        $this->router->registerExpert($expert);
        return $this;
    }

    /**
     * 发送聊天请求
     *
     * @param string $message 用户消息
     * @param array{
     *     capability?: string,
     *     preferred_platform?: string,
     *     preferred_model?: string,
     *     max_cost?: float,
     *     temperature?: float,
     *     max_tokens?: int,
     * } $options 选项
     */
    #[\NoDiscard]
    public function chat(string $message, array $options = []): ResponseInterface
    {
        $prompt = new \Kode\AiAgent\Domain\Model\Prompt($message);
        return $this->dispatch($prompt, $options);
    }

    /**
     * 智能聊天（自动压缩 + 自动路由 + 预算控制）
     *
     * 一键式入口，自动启用压缩、路由、统计，降低用户使用门槛，增强依赖。
     *
     * @param string $message 用户消息
     * @param array<string, mixed> $options 选项
     */
    #[\NoDiscard]
    public function smartChat(string $message, array $options = []): ResponseInterface
    {
        $options['auto_compress'] = $options['auto_compress'] ?? true;
        $options['strategy'] = $options['strategy'] ?? 'token_balanced';

        return $this->chat($message, $options);
    }

    /**
     * 发送多模态请求（带图片）
     *
     * @param string $text 文本
     * @param array<int, string> $images 图片 URL 或 base64
     * @param array<string, mixed> $options 选项
     */
    #[\NoDiscard]
    public function vision(string $text, array $images = [], array $options = []): ResponseInterface
    {
        $prompt = new \Kode\AiAgent\Domain\Model\Prompt($text, $images);
        $options['capability'] = $options['capability'] ?? 'vision';
        return $this->router->dispatch($prompt, $options);
    }

    /**
     * 流式响应
     */
    #[\NoDiscard]
    public function stream(string $message, array $options = []): \Generator
    {
        $prompt = new \Kode\AiAgent\Domain\Model\Prompt($message);
        $prompt = $this->applyAutoCompression($prompt, $options);
        return $this->router->stream($prompt, $options);
    }

    /**
     * 发送 Prompt 对象
     */
    #[\NoDiscard]
    public function dispatch(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        $prompt = $this->applyAutoCompression($prompt, $options);
        return $this->router->dispatch($prompt, $options);
    }

    /**
     * 获取路由器
     */
    public function router(): ModelRouter
    {
        return $this->router;
    }

    /**
     * 获取 Token 预算
     */
    public function budget(): TokenBudget
    {
        return $this->router->budget();
    }

    /**
     * 获取价格表
     */
    public function priceTable(): ModelPriceTable
    {
        return $this->router->priceTable();
    }

    /**
     * 获取所有专家
     *
     * @return array<int, ExpertInterface>
     */
    public function experts(): array
    {
        return $this->router->experts();
    }

    /**
     * 获取统计信息
     */
    public function statistics(): array
    {
        return $this->router->statistics();
    }

    /**
     * 获取使用报告
     */
    public function report(): array
    {
        $stats = $this->router->statistics();
        $totalTokens = 0;
        $totalCost = 0.0;
        $totalCount = 0;
        $totalSuccess = 0;

        foreach ($stats as $expertId => $row) {
            $totalTokens += $row['total_tokens'];
            $totalCost += $row['total_cost'];
            $totalCount += $row['count'];
            $totalSuccess += $row['success'];
        }

        return [
            'experts' => array_map(
                static fn(ExpertInterface $e) => [
                    'id' => $e->id(),
                    'platform' => $e->platform(),
                    'model' => $e->model(),
                    'capabilities' => $e->capabilities(),
                    'healthy' => $e->isHealthy(),
                    'priority' => $e->priority(),
                    'weight' => $e->weight(),
                ],
                $this->router->experts()
            ),
            'totals' => [
                'request_count' => $totalCount,
                'success_count' => $totalSuccess,
                'failed_count' => $totalCount - $totalSuccess,
                'total_tokens' => $totalTokens,
                'total_cost' => round($totalCost, 6),
            ],
            'budget' => $this->router->budget()->remaining(),
        ];
    }

    /**
     * 获取自动压缩中间件
     */
    public function autoCompression(): AutoCompressionMiddleware
    {
        return $this->autoCompression;
    }

    /**
     * 解析自动压缩配置
     */
    private function resolveAutoCompression(bool|array $config): AutoCompressionMiddleware
    {
        if ($config === false) {
            return new AutoCompressionMiddleware(enabled: false);
        }

        if ($config === true) {
            return new AutoCompressionMiddleware();
        }

        return new AutoCompressionMiddleware(
            threshold: (int) ($config['threshold'] ?? 1000),
            targetRatio: (float) ($config['target_ratio'] ?? 0.75),
            minTokens: (int) ($config['min_tokens'] ?? 100),
            enabled: (bool) ($config['enabled'] ?? true),
        );
    }

    /**
     * 应用自动压缩
     */
    private function applyAutoCompression(PromptInterface $prompt, array $options): PromptInterface
    {
        $enabled = $options['auto_compress'] ?? $this->autoCompression->enabled();
        if (!$enabled) {
            return $prompt;
        }

        $savings = $this->autoCompression->savings($prompt);
        if ($savings['saved'] > 0) {
            $this->logger->info('MOE 自动压缩', [
                'original_tokens' => $savings['original'],
                'compressed_tokens' => $savings['compressed'],
                'saved_tokens' => $savings['saved'],
                'ratio' => $savings['ratio'],
            ]);
        }

        return $this->autoCompression->compress($prompt);
    }
}
