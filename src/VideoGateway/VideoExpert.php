<?php

declare(strict_types=1);

namespace Kode\AiAgent\VideoGateway;

use Kode\AiAgent\Domain\Contract\VideoProviderInterface;
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;

/**
 * 视频专家
 *
 * 封装一个具体的视频供应商（Seedance / 通义万相 / 数字人），
 * 附加能力标签、优先级、权重、健康度等元数据，供路由器决策。
 *
 * @package Kode\AiAgent\VideoGateway
 */
final class VideoExpert
{
    private bool $healthy = true;
    private ?string $unhealthyReason = null;
    private ?float $unhealthyUntil = null;

    /**
     * @param VideoProviderInterface $provider 底层视频供应商
     * @param string|null $id 自定义专家 ID（默认 platform:model）
     * @param array<int, MultimodalCapability> $capabilities 能力标签
     * @param int $priority 优先级（数值越小越高）
     * @param float $weight 权重（加权随机用）
     * @param int $unhealthyTtlSec 不健康状态持续时间（秒），0 表示永久
     */
    public function __construct(
        private readonly VideoProviderInterface $provider,
        private readonly ?string $id = null,
        private readonly array $capabilities = [MultimodalCapability::TEXT_TO_VIDEO],
        private readonly int $priority = 100,
        private readonly float $weight = 1.0,
        private readonly int $unhealthyTtlSec = 60,
    ) {}

    public function supports(MultimodalCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    #[\NoDiscard]
    public function textToVideo(string $prompt, array $options = []): \Kode\AiAgent\Domain\Model\VideoResponse
    {
        return $this->provider->textToVideo($prompt, $options);
    }

    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): \Kode\AiAgent\Domain\Model\VideoResponse
    {
        return $this->provider->imageToVideo($image, $prompt, $options);
    }

    #[\NoDiscard]
    public function generateAvatar(string $text, array $options = []): \Kode\AiAgent\Domain\Model\VideoResponse
    {
        return $this->provider->generateAvatar($text, $options);
    }

    public function getProgress(string $taskId): array
    {
        return $this->provider->getProgress($taskId);
    }

    public function estimateCost(array $options = []): float
    {
        return $this->provider->estimateCost($options);
    }

    public function id(): string
    {
        return $this->id ?? $this->provider->name() . ':' . $this->provider->model();
    }

    public function name(): string
    {
        return $this->provider->name();
    }

    public function platform(): string
    {
        return $this->provider->name();
    }

    public function model(): string
    {
        return $this->provider->model();
    }

    /**
     * @return array<int, MultimodalCapability>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function weight(): float
    {
        return max(0.0, $this->weight);
    }

    public function isHealthy(): bool
    {
        if ($this->healthy) {
            return true;
        }

        if ($this->unhealthyUntil !== null && microtime(true) >= $this->unhealthyUntil) {
            $this->healthy = true;
            $this->unhealthyReason = null;
            $this->unhealthyUntil = null;
            return true;
        }

        return false;
    }

    public function markUnhealthy(string $reason = ''): void
    {
        $this->healthy = false;
        $this->unhealthyReason = $reason;
        $this->unhealthyUntil = $this->unhealthyTtlSec > 0
            ? microtime(true) + $this->unhealthyTtlSec
            : null;
    }

    public function markHealthy(): void
    {
        $this->healthy = true;
        $this->unhealthyReason = null;
        $this->unhealthyUntil = null;
    }

    public function provider(): VideoProviderInterface
    {
        return $this->provider;
    }

    public function unhealthyReason(): ?string
    {
        return $this->unhealthyReason;
    }
}
