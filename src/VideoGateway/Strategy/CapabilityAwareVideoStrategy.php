<?php

declare(strict_types=1);

namespace Kode\AiAgent\VideoGateway\Strategy;

use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\VideoGateway\VideoExpert;

/**
 * 能力感知路由策略
 *
 * 优先满足能力要求，其次尊重偏好模型/平台，
 * 最后按优先级（数值越小越高）排序，同等优先级按权重随机。
 *
 * @package Kode\AiAgent\VideoGateway\Strategy
 */
final class CapabilityAwareVideoStrategy implements VideoRoutingStrategyInterface
{
    public function name(): string
    {
        return 'capability_aware';
    }

    public function select(
        array $experts,
        string $capability,
        ?float $maxCost = null,
        ?string $preferredModel = null,
        ?string $preferredPlatform = null,
    ): VideoExpert {
        if ($experts === []) {
            throw new \RuntimeException('没有可用的视频专家');
        }

        // 偏好模型优先
        if ($preferredModel !== null) {
            foreach ($experts as $expert) {
                if ($expert->model() === $preferredModel) {
                    return $expert;
                }
            }
        }

        // 偏好平台其次
        if ($preferredPlatform !== null) {
            foreach ($experts as $expert) {
                if ($expert->platform() === $preferredPlatform) {
                    return $expert;
                }
            }
        }

        // 按优先级分组，组内按权重随机
        usort($experts, static fn(VideoExpert $a, VideoExpert $b) => $a->priority() <=> $b->priority());

        $topPriority = $experts[0]->priority();
        $topGroup = array_values(array_filter(
            $experts,
            static fn(VideoExpert $e) => $e->priority() === $topPriority
        ));

        if (count($topGroup) === 1) {
            return $topGroup[0];
        }

        $totalWeight = 0.0;
        foreach ($topGroup as $expert) {
            $totalWeight += $expert->weight();
        }

        $rand = mt_rand() / mt_getrandmax() * $totalWeight;
        $acc = 0.0;
        foreach ($topGroup as $expert) {
            $acc += $expert->weight();
            if ($rand <= $acc) {
                return $expert;
            }
        }

        return $topGroup[count($topGroup) - 1];
    }
}
