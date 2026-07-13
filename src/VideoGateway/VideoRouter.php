<?php

declare(strict_types=1);

namespace Kode\AiAgent\VideoGateway;

use Kode\AiAgent\Domain\Model\VideoResponse;
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\VideoGateway\Strategy\CapabilityAwareVideoStrategy;
use Kode\AiAgent\VideoGateway\Strategy\CostAwareVideoStrategy;
use Kode\AiAgent\VideoGateway\Strategy\RoundRobinVideoStrategy;
use Kode\AiAgent\VideoGateway\Strategy\VideoRoutingStrategyInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 视频路由器
 *
 * 统一视频网关的核心组件：根据能力、成本、健康度，
 * 在多个视频供应商（专家）间自动选择最优者，并支持失败自动转移。
 *
 * @package Kode\AiAgent\VideoGateway
 */
final class VideoRouter
{
    /** @var array<int, VideoExpert> */
    private array $experts = [];

    private VideoRoutingStrategyInterface $strategy;
    private LoggerInterface $logger;
    private VideoPriceTable $priceTable;

    /** @var array<string, array{count: int, success: int, failed: int, total_cost: float, last_used: float}> */
    private array $statistics = [];

    public function __construct(
        ?VideoRoutingStrategyInterface $strategy = null,
        ?LoggerInterface $logger = null,
        ?VideoPriceTable $priceTable = null,
    ) {
        $this->strategy = $strategy ?? new CapabilityAwareVideoStrategy();
        $this->logger = $logger ?? new NullLogger();
        $this->priceTable = $priceTable ?? new VideoPriceTable();
    }

    public function registerExpert(VideoExpert $expert, ?array $capabilities = null): self
    {
        $this->experts[] = $expert;
        $this->logger->info('注册视频专家', [
            'id' => $expert->id(),
            'platform' => $expert->platform(),
            'model' => $expert->model(),
            'capabilities' => array_map(static fn($c) => $c->value, $expert->capabilities()),
        ]);
        return $this;
    }

    /**
     * @param array<int, VideoExpert> $experts
     */
    public function registerExperts(iterable $experts): self
    {
        foreach ($experts as $expert) {
            $this->registerExpert($expert);
        }
        return $this;
    }

    public function setStrategy(VideoRoutingStrategyInterface $strategy): self
    {
        $this->strategy = $strategy;
        return $this;
    }

    public function strategy(): VideoRoutingStrategyInterface
    {
        return $this->strategy;
    }

    public function priceTable(): VideoPriceTable
    {
        return $this->priceTable;
    }

    /**
     * @return array<int, VideoExpert>
     */
    public function experts(): array
    {
        return $this->experts;
    }

    public function statistics(): array
    {
        return $this->statistics;
    }

    #[\NoDiscard]
    public function textToVideo(string $prompt, array $options = []): VideoResponse
    {
        return $this->dispatch(
            MultimodalCapability::TEXT_TO_VIDEO,
            static fn(VideoExpert $e) => $e->textToVideo($prompt, $options),
            $options
        );
    }

    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt, array $options = []): VideoResponse
    {
        return $this->dispatch(
            MultimodalCapability::IMAGE_TO_VIDEO,
            static fn(VideoExpert $e) => $e->imageToVideo($image, $prompt, $options),
            $options
        );
    }

    #[\NoDiscard]
    public function avatar(string $text, array $options = []): VideoResponse
    {
        return $this->dispatch(
            MultimodalCapability::AVATAR_GENERATION,
            static fn(VideoExpert $e) => $e->generateAvatar($text, $options),
            $options
        );
    }

    public function getProgress(string $taskId): array
    {
        return $this->dispatch(
            MultimodalCapability::PROGRESS_TRACKING,
            static fn(VideoExpert $e) => $e->getProgress($taskId),
            []
        )->toArray();
    }

    /**
     * 核心分发：按能力选专家，失败自动转移到下一个健康专家
     *
     * @param \Closure(VideoExpert): mixed $action
     * @param array<string, mixed> $options
     */
    private function dispatch(MultimodalCapability $capability, \Closure $action, array $options): VideoResponse
    {
        $capabilityValue = $options['capability'] ?? $capability->value;
        $preferredModel = $options['preferred_model'] ?? null;
        $preferredPlatform = $options['preferred_platform'] ?? null;
        $maxCost = $options['max_cost'] ?? null;

        $tried = [];
        $lastError = null;

        while (true) {
            $candidates = array_values(array_filter(
                $this->experts,
                static function (VideoExpert $e) use ($capability, $tried): bool {
                    return $e->isHealthy()
                        && $e->supports($capability)
                        && !in_array($e->id(), $tried, true);
                }
            ));

            if ($candidates === []) {
                if ($lastError instanceof \Throwable) {
                    throw $lastError;
                }
                throw new \RuntimeException("没有可用的视频专家支持能力：{$capabilityValue}");
            }

            $expert = $this->strategy->select(
                $candidates,
                $capabilityValue,
                $maxCost !== null ? (float) $maxCost : null,
                $preferredModel !== null ? (string) $preferredModel : null,
                $preferredPlatform !== null ? (string) $preferredPlatform : null,
            );

            $tried[] = $expert->id();
            $this->logger->debug('视频路由', [
                'expert' => $expert->id(),
                'platform' => $expert->platform(),
                'model' => $expert->model(),
                'capability' => $capabilityValue,
                'strategy' => $this->strategy->name(),
            ]);

            $startTime = microtime(true);
            try {
                /** @var VideoResponse $response */
                $response = $action($expert);
                $this->recordSuccess($expert, $response, microtime(true) - $startTime, $options);
                return $response;
            } catch (\Throwable $e) {
                $this->recordFailure($expert);
                $expert->markUnhealthy($e->getMessage());
                $this->logger->warning('视频专家调用失败，尝试转移', [
                    'expert' => $expert->id(),
                    'error' => $e->getMessage(),
                ]);
                $lastError = $e;
            }
        }
    }

    private function recordSuccess(VideoExpert $expert, VideoResponse $response, float $duration, array $options): void
    {
        $id = $expert->id();
        if (!isset($this->statistics[$id])) {
            $this->statistics[$id] = [
                'count' => 0, 'success' => 0, 'failed' => 0,
                'total_cost' => 0.0, 'last_used' => 0.0,
            ];
        }

        $cost = $this->priceTable->estimate($expert->model(), $options);

        $this->statistics[$id]['count']++;
        $this->statistics[$id]['success']++;
        $this->statistics[$id]['total_cost'] += $cost;
        $this->statistics[$id]['last_used'] = microtime(true);
    }

    private function recordFailure(VideoExpert $expert): void
    {
        $id = $expert->id();
        if (!isset($this->statistics[$id])) {
            $this->statistics[$id] = [
                'count' => 0, 'success' => 0, 'failed' => 0,
                'total_cost' => 0.0, 'last_used' => 0.0,
            ];
        }
        $this->statistics[$id]['count']++;
        $this->statistics[$id]['failed']++;
    }
}
