<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support\Builder;

use Kode\AiAgent\VideoGateway\VideoGateway;

/**
 * 统一视频网关构建器
 *
 * 链式配置 VideoGateway：添加各平台视频供应商（Seedance / 通义万相 / 数字人）、
 * 设置路由策略、配置价格表等。
 *
 * @package Kode\AiAgent\Support\Builder
 *
 * @example
 * ```php
 * $gateway = VideoGatewayBuilder::create()
 *     ->strategy('cost_aware')
 *     ->addSeedance(env('VOLC_API_KEY'), ['version' => '2.5'], priority: 10)
 *     ->addWanxiang(env('DASHSCOPE_API_KEY'), [], priority: 20)
 *     ->addAliyunAvatar(env('DASHSCOPE_API_KEY'), [], priority: 30)
 *     ->build();
 * ```
 */
final class VideoGatewayBuilder
{
    /** @var array<int, array{type: string, api_key: string, options: array, priority: int, weight: float}> */
    private array $providerConfigs = [];

    private string $strategy = 'capability_aware';
    private ?\Psr\Log\LoggerInterface $logger = null;

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    public function strategy(string $strategy): self
    {
        $this->strategy = $strategy;
        return $this;
    }

    public function addSeedance(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0): self
    {
        $this->providerConfigs[] = [
            'type' => 'seedance',
            'api_key' => $apiKey,
            'options' => $options,
            'priority' => $priority,
            'weight' => $weight,
        ];
        return $this;
    }

    public function addWanxiang(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0): self
    {
        $this->providerConfigs[] = [
            'type' => 'wanxiang',
            'api_key' => $apiKey,
            'options' => $options,
            'priority' => $priority,
            'weight' => $weight,
        ];
        return $this;
    }

    public function addAliyunAvatar(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0): self
    {
        $this->providerConfigs[] = [
            'type' => 'aliyun_avatar',
            'api_key' => $apiKey,
            'options' => $options,
            'priority' => $priority,
            'weight' => $weight,
        ];
        return $this;
    }

    public function logger(\Psr\Log\LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function build(): VideoGateway
    {
        $gateway = new VideoGateway(
            strategy: $this->strategy,
            logger: $this->logger,
        );

        foreach ($this->providerConfigs as $config) {
            match ($config['type']) {
                'seedance' => $gateway->addSeedance(
                    $config['api_key'], $config['options'], $config['priority'], $config['weight']
                ),
                'wanxiang' => $gateway->addWanxiang(
                    $config['api_key'], $config['options'], $config['priority'], $config['weight']
                ),
                'aliyun_avatar' => $gateway->addAliyunAvatar(
                    $config['api_key'], $config['options'], $config['priority'], $config['weight']
                ),
                default => throw new \InvalidArgumentException('未知视频供应商类型：' . $config['type']),
            };
        }

        return $gateway;
    }
}
