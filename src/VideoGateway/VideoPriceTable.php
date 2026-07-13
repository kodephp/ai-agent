<?php

declare(strict_types=1);

namespace Kode\AiAgent\VideoGateway;

/**
 * 视频生成价格表
 *
 * 统一管理各视频模型的单次生成成本（美元/次），
 * 用于成本估算、成本感知路由与统计报表。
 *
 * 价格数据来源：各平台官方公开定价（2025-2026），仅供路由参考。
 *
 * @package Kode\AiAgent\VideoGateway
 */
final class VideoPriceTable
{
    /**
     * 价格表：model => 单次生成成本（美元）
     */
    private const PRICES = [
        // 字节跳动 Seedance
        'seedance-2.0-pro' => 0.08,
        'seedance-2.0-lite' => 0.04,
        'seedance-2.5-pro' => 0.10,
        'seedance-2.5-lite' => 0.05,
        // 阿里通义万相视频
        'wanx2.1-t2v-plus' => 0.07,
        'wanx2.1-t2v-turbo' => 0.03,
        'wanx2.1-i2v-plus' => 0.09,
        'wanx2.1-i2v-turbo' => 0.04,
        // 阿里数字人
        'aliyun-avatar' => 0.20,
    ];

    /** @var array<string, float> */
    private array $customPrices = [];

    /**
     * 获取模型单次生成成本
     */
    public function price(string $model): float
    {
        $table = $this->customPrices + self::PRICES;
        return $table[$model] ?? $table['default'] ?? 0.05;
    }

    /**
     * 设置模型单次生成成本
     */
    public function set(string $model, float $cost): self
    {
        $this->customPrices[$model] = $cost;
        return $this;
    }

    /**
     * 估算成本
     */
    public function estimate(string $model, array $options = []): float
    {
        $base = $this->price($model);

        // 高清分辨率轻微上浮
        $resolution = strtolower((string) ($options['resolution'] ?? ''));
        if ($resolution === '1080p') {
            $base *= 1.2;
        }

        // 时长越长成本越高（以 10s 为基准）
        $duration = (float) ($options['duration'] ?? 10);
        if ($duration > 0) {
            $base *= $duration / 10;
        }

        return round($base, 4);
    }
}
