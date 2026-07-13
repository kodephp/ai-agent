<?php

declare(strict_types=1);

namespace Kode\AiAgent\VideoGateway;

use Kode\AiAgent\Domain\Contract\VideoProviderInterface;
use Kode\AiAgent\Domain\Model\VideoResponse;
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\Infrastructure\Adapter\SeedanceAdapter;
use Kode\AiAgent\VideoGateway\Provider\AliyunAvatarProvider;
use Kode\AiAgent\VideoGateway\Provider\SeedanceVideoProvider;
use Kode\AiAgent\VideoGateway\Provider\WanxiangVideoProvider;
use Kode\AiAgent\VideoGateway\Strategy\CapabilityAwareVideoStrategy;
use Kode\AiAgent\VideoGateway\Strategy\CostAwareVideoStrategy;
use Kode\AiAgent\VideoGateway\Strategy\RoundRobinVideoStrategy;
use Kode\AiAgent\VideoGateway\Strategy\VideoRoutingStrategyInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 统一视频网关
 *
 * 多供应商视频生成（Seedance 2.0/2.5、阿里通义万相、阿里数字人等）
 * 的统一入口。后台分别配置各平台 Key，用户只感知一个网关，
 * 内部按能力 / 成本 / 健康度自动选择最优供应商，并在失败时自动转移。
 *
 * @package Kode\AiAgent\VideoGateway
 *
 * @example
 * ```php
 * $gateway = new VideoGateway();
 * $gateway->addSeedance('sk-volc-xxx', ['version' => '2.5']);
 * $gateway->addWanxiang('sk-dashscope-xxx');
 * $gateway->addAliyunAvatar('sk-dashscope-xxx');
 *
 * // 文生视频（自动选最优供应商）
 * $video = $gateway->textToVideo('一只猫咪在草地上玩耍', [
 *     'capability' => 'text_to_video',
 * ]);
 *
 * // 数字人
 * $avatar = $gateway->avatar('大家好，欢迎使用！', [
 *     'avatar_id' => 'default-female',
 * ]);
 * ```
 */
final class VideoGateway
{
    private VideoRouter $router;
    private LoggerInterface $logger;

    /**
     * @param string $strategy 路由策略：capability_aware|cost_aware|round_robin
     */
    public function __construct(
        string $strategy = 'capability_aware',
        ?LoggerInterface $logger = null,
        ?VideoPriceTable $priceTable = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $priceTable = $priceTable ?? new VideoPriceTable();

        $strategyInstance = match ($strategy) {
            'cost_aware' => new CostAwareVideoStrategy($priceTable),
            'round_robin' => new RoundRobinVideoStrategy(),
            default => new CapabilityAwareVideoStrategy(),
        };

        $this->router = new VideoRouter($strategyInstance, $this->logger, $priceTable);
    }

    /**
     * 注册视频供应商
     *
     * @param array<int, MultimodalCapability>|null $capabilities 不传则使用供应商自身声明的能力
     * @param int $priority 优先级（数值越小越高）
     * @param float $weight 权重
     */
    public function addProvider(
        VideoProviderInterface $provider,
        ?array $capabilities = null,
        int $priority = 100,
        float $weight = 1.0,
    ): self {
        $caps = $capabilities ?? $provider->supportedCapabilities();
        $expert = new VideoExpert(
            provider: $provider,
            capabilities: $caps,
            priority: $priority,
            weight: $weight,
        );
        $this->router->registerExpert($expert);
        return $this;
    }

    /**
     * 便捷：添加字节跳动 Seedance 视频供应商
     *
     * @param array<string, mixed> $options version(2.0/2.5)/tier(pro/lite)/resolution 等
     */
    public function addSeedance(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0): self
    {
        $provider = new SeedanceVideoProvider($apiKey, $options);
        return $this->addProvider($provider, null, $priority, $weight);
    }

    /**
     * 便捷：添加阿里通义万相视频供应商（文生视频 / 图生视频）
     */
    public function addWanxiang(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0): self
    {
        $provider = new WanxiangVideoProvider($apiKey, $options);
        return $this->addProvider($provider, null, $priority, $weight);
    }

    /**
     * 便捷：添加阿里数字人供应商
     */
    public function addAliyunAvatar(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0): self
    {
        $provider = new AliyunAvatarProvider($apiKey, $options);
        return $this->addProvider($provider, null, $priority, $weight);
    }

    /**
     * 文本生成视频
     *
     * @param array<string, mixed> $options capability/preferred_model/preferred_platform/max_cost 等
     */
    #[\NoDiscard]
    public function textToVideo(string $prompt, array $options = []): VideoResponse
    {
        return $this->router->textToVideo($prompt, $options);
    }

    /**
     * 图像生成视频
     */
    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
    {
        return $this->router->imageToVideo($image, $prompt, $options);
    }

    /**
     * 生成数字人视频
     */
    #[\NoDiscard]
    public function avatar(string $text, array $options = []): VideoResponse
    {
        return $this->router->avatar($text, $options);
    }

    /**
     * 获取异步任务进度
     *
     * @return array<string, mixed>
     */
    public function getProgress(string $taskId): array
    {
        return $this->router->getProgress($taskId);
    }

    public function router(): VideoRouter
    {
        return $this->router;
    }

    public function priceTable(): VideoPriceTable
    {
        return $this->router->priceTable();
    }

    /**
     * @return array<int, VideoExpert>
     */
    public function experts(): array
    {
        return $this->router->experts();
    }

    public function statistics(): array
    {
        return $this->router->statistics();
    }

    /**
     * 使用报告
     */
    public function report(): array
    {
        $stats = $this->router->statistics();
        $totalCount = 0;
        $totalSuccess = 0;
        $totalCost = 0.0;

        foreach ($stats as $row) {
            $totalCount += $row['count'];
            $totalSuccess += $row['success'];
            $totalCost += $row['total_cost'];
        }

        return [
            'experts' => array_map(
                static fn(VideoExpert $e) => [
                    'id' => $e->id(),
                    'platform' => $e->platform(),
                    'model' => $e->model(),
                    'capabilities' => array_map(static fn($c) => $c->value, $e->capabilities()),
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
                'total_cost' => round($totalCost, 6),
            ],
        ];
    }
}
