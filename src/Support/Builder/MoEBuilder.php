<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support\Builder;

use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;
use Kode\AiAgent\Moe\MoEGateway;

/**
 * MOE 网关构建器
 *
 * 链式配置 MoEGateway：添加专家、设置预算、选择策略、注册缓存等。
 *
 * @package Kode\AiAgent\Support\Builder
 *
 * @example
 * ```php
 * $gateway = MoEBuilder::create()
 *     ->strategy('cost_aware')
 *     ->budget(perDayTokens: 1_000_000, perMonthCost: 50.0)
 *     ->addExpert('openai', env('OPENAI_API_KEY'), ['chat', 'vision'], priority: 10)
 *     ->addExpert('deepseek', env('DEEPSEEK_API_KEY'), ['chat', 'code'], priority: 20)
 *     ->addExpert('aliyun', env('ALIYUN_API_KEY'), ['chat'], priority: 30, weight: 2.0)
 *     ->build();
 * ```
 */
final class MoEBuilder
{
    /** @var array<int, array{platform: string, api_key: string|array, capabilities: array, model: ?string, priority: int, weight: float, options: array}> */
    private array $expertConfigs = [];

    private string $strategy = 'capability_aware';
    private ?int $perMinuteTokens = null;
    private ?int $perDayTokens = null;
    private ?float $perMonthCost = null;
    private ?\Psr\Log\LoggerInterface $logger = null;
    private ?\Psr\SimpleCache\CacheInterface $cache = null;

    private function __construct() {}

    /**
     * 创建构建器
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * 设置路由策略
     */
    public function strategy(string $strategy): self
    {
        $this->strategy = $strategy;
        return $this;
    }

    /**
     * 设置 Token 预算
     */
    public function budget(
        ?int $perMinuteTokens = null,
        ?int $perDayTokens = null,
        ?float $perMonthCost = null,
    ): self {
        $this->perMinuteTokens = $perMinuteTokens;
        $this->perDayTokens = $perDayTokens;
        $this->perMonthCost = $perMonthCost;
        return $this;
    }

    /**
     * 添加专家
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
        $this->expertConfigs[] = [
            'platform' => $platform,
            'api_key' => $apiKey,
            'capabilities' => $capabilities,
            'model' => $model,
            'priority' => $priority,
            'weight' => $weight,
            'options' => $options,
        ];
        return $this;
    }

    /**
     * 从环境变量批量添加专家
     *
     * @param array<string, array{key: string, capabilities?: array, model?: string, priority?: int}> $configs
     */
    public function addFromEnv(array $configs): self
    {
        foreach ($configs as $platform => $config) {
            $apiKey = getenv($config['key']) ?: '';
            if ($apiKey === '') {
                continue;
            }
            $this->addExpert(
                $platform,
                $apiKey,
                $config['capabilities'] ?? ['chat'],
                $config['model'] ?? null,
                $config['priority'] ?? 100,
            );
        }
        return $this;
    }

    /**
     * 设置日志
     */
    public function logger(\Psr\Log\LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * 设置缓存
     */
    public function cache(\Psr\SimpleCache\CacheInterface $cache): self
    {
        $this->cache = $cache;
        return $this;
    }

    /**
     * 构建 MoEGateway
     */
    public function build(): MoEGateway
    {
        $gateway = new MoEGateway(
            budgetConfig: [
                'per_minute_tokens' => $this->perMinuteTokens,
                'per_day_tokens' => $this->perDayTokens,
                'per_month_cost' => $this->perMonthCost,
            ],
            strategy: $this->strategy,
            logger: $this->logger,
        );

        foreach ($this->expertConfigs as $config) {
            $gateway->addExpert(
                $config['platform'],
                $config['api_key'],
                $config['capabilities'],
                $config['model'],
                $config['priority'],
                $config['weight'],
                $config['options'],
            );
        }

        return $gateway;
    }
}
