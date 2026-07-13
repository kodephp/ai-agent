<?php

declare(strict_types=1);

namespace Kode\AiAgent\VideoGateway\Strategy;

use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\VideoGateway\VideoExpert;

/**
 * 视频路由策略接口
 *
 * @package Kode\AiAgent\VideoGateway\Strategy
 */
interface VideoRoutingStrategyInterface
{
    /**
     * 策略名称
     */
    public function name(): string;

    /**
     * 从候选专家中选择一个最合适的专家
     *
     * @param array<int, VideoExpert> $experts 已过滤出健康且支持能力的专家
     * @param string $capability 目标能力字符串（MultimodalCapability 的 value）
     * @param float|null $maxCost 单次最大成本（美元）
     * @param string|null $preferredModel 偏好模型
     * @param string|null $preferredPlatform 偏好平台
     */
    public function select(
        array $experts,
        string $capability,
        ?float $maxCost = null,
        ?string $preferredModel = null,
        ?string $preferredPlatform = null,
    ): VideoExpert;
}
