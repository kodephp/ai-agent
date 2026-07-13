<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

/**
 * 转场效果配置
 */
final class TransitionEffect
{
    public function __construct(
        public TransitionType $type,
        public float $duration = 1,
        public string $easing = 'ease-in-out',
        public array $options = [],
    ) {}

    /**
     * 获取 FFmpeg 滤镜字符串
     */
    public function toFFmpegFilter(): string
    {
        return match ($this->type) {
            TransitionType::FADE => $this->buildFadeFilter(),
            TransitionType::DISSOLVE => $this->buildDissolveFilter(),
            TransitionType::SLIDE_LEFT => $this->buildSlideFilter('in', 'left'),
            TransitionType::SLIDE_RIGHT => $this->buildSlideFilter('in', 'right'),
            TransitionType::SLIDE_UP => $this->buildSlideFilter('in', 'up'),
            TransitionType::SLIDE_DOWN => $this->buildSlideFilter('in', 'down'),
            TransitionType::ZOOM_IN => $this->buildZoomFilter('in'),
            TransitionType::ZOOM_OUT => $this->buildZoomFilter('out'),
            TransitionType::BLUR => $this->buildBlurFilter(),
            TransitionType::CROSS_WIPE => $this->buildCrossWipeFilter(),
            TransitionType::RADIAL_BLUR => $this->buildRadialBlurFilter(),
        };
    }

    private function buildFadeFilter(): string
    {
        return sprintf('fade=t=in:st=0:d=%.2f,fade=t=out:st=%.2f:d=%.2f',
            $this->duration * 0.3,
            $this->duration - $this->duration * 0.3,
            $this->duration * 0.3
        );
    }

    private function buildDissolveFilter(): string
    {
        return sprintf('fade=t=in:st=0:d=%.2f', $this->duration);
    }

    private function buildSlideFilter(string $direction, string $slideFrom): string
    {
        $offset = $this->options['offset'] ?? 0;
        return sprintf(
            'slide=%s:%s:%d:%.2f',
            $direction,
            $slideFrom,
            $offset,
            $this->duration
        );
    }

    private function buildZoomFilter(string $direction): string
    {
        $scale = $direction === 'in' ? 1.0 : 0.5;
        return sprintf(
            'zoompan=z=\'%s\':d=%.2f:s=1920x1080',
            $direction === 'in' ? 'min(zoom+0.001,1.5)' : 'max(zoom-0.001,0.5)',
            $this->duration
        );
    }

    private function buildBlurFilter(): string
    {
        $radius = $this->options['radius'] ?? 10;
        return sprintf('boxblur=%d:%.2f', $radius, $this->duration);
    }

    private function buildCrossWipeFilter(): string
    {
        return sprintf('wiperight=%.2f', $this->duration);
    }

    private function buildRadialBlurFilter(): string
    {
        $angle = $this->options['angle'] ?? 10;
        return sprintf('radialblur=%d:%.2f', $angle, $this->duration);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'duration' => $this->duration,
            'easing' => $this->easing,
            'options' => $this->options,
        ];
    }
}

/**
 * 转场效果管理器
 *
 * 管理场景之间的转场效果，支持多种转场类型和自定义配置。
 *
 * @package Kode\AiAgent\Drama
 *
 * @example
 * ```php
 * $manager = new TransitionManager();
 *
 * // 添加转场
 * $manager->addTransition('scene-1', 'scene-2', TransitionType::FADE, 1);
 *
 * // 获取转场
 * $effect = $manager->getTransition('scene-1', 'scene-2');
 * echo $effect->toFFmpegFilter();
 * ```
 */
final class TransitionManager
{
    /** @var array<string, TransitionEffect> 转场效果映射 */
    private array $transitions = [];

    /** @var TransitionType 默认转场类型 */
    private TransitionType $defaultType = TransitionType::FADE;

    /** @var int 默认转场时长（秒） */
    private int $defaultDuration = 1;

    /**
     * 添加转场效果
     *
     * @param string $fromSceneId 起始场景 ID
     * @param string $toSceneId 目标场景 ID
     * @param TransitionType $type 转场类型
     * @param float $duration 转场时长（秒）
     * @param array $options 额外配置选项
     */
    public function addTransition(
        string $fromSceneId,
        string $toSceneId,
        TransitionType $type,
        float $duration = 1,
        array $options = [],
    ): self {
        $key = $this->makeKey($fromSceneId, $toSceneId);
        $this->transitions[$key] = new TransitionEffect($type, $duration, 'ease-in-out', $options);

        return $this;
    }

    /**
     * 添加默认转场
     */
    public function addDefaultTransition(string $fromSceneId, string $toSceneId): self
    {
        return $this->addTransition($fromSceneId, $toSceneId, $this->defaultType, $this->defaultDuration);
    }

    /**
     * 获取转场效果
     */
    public function getTransition(string $fromSceneId, string $toSceneId): ?TransitionEffect
    {
        $key = $this->makeKey($fromSceneId, $toSceneId);
        return $this->transitions[$key] ?? null;
    }

    /**
     * 检查是否存在转场
     */
    public function hasTransition(string $fromSceneId, string $toSceneId): bool
    {
        $key = $this->makeKey($fromSceneId, $toSceneId);
        return isset($this->transitions[$key]);
    }

    /**
     * 移除转场
     */
    public function removeTransition(string $fromSceneId, string $toSceneId): self
    {
        $key = $this->makeKey($fromSceneId, $toSceneId);
        unset($this->transitions[$key]);

        return $this;
    }

    /**
     * 设置默认转场类型
     */
    public function setDefaultType(TransitionType $type): self
    {
        $this->defaultType = $type;
        return $this;
    }

    /**
     * 设置默认转场时长
     */
    public function setDefaultDuration(int $duration): self
    {
        $this->defaultDuration = max(1, $duration);
        return $this;
    }

    /**
     * 为场景列表批量添加转场
     *
     * @param EnhancedScene[] $scenes 场景数组
     */
    public function addTransitionsForScenes(array $scenes): self
    {
        for ($i = 0; $i < count($scenes) - 1; $i++) {
            $from = $scenes[$i];
            $to = $scenes[$i + 1];

            if (!$this->hasTransition($from->id, $to->id)) {
                $this->addDefaultTransition($from->id, $to->id);
            }
        }

        return $this;
    }

    /**
     * 获取所有转场
     */
    public function all(): array
    {
        return $this->transitions;
    }

    /**
     * 获取转场数量
     */
    public function count(): int
    {
        return count($this->transitions);
    }

    /**
     * 清除所有转场
     */
    public function clear(): self
    {
        $this->transitions = [];
        return $this;
    }

    /**
     * 生成转场键名
     */
    private function makeKey(string $fromSceneId, string $toSceneId): string
    {
        return "{$fromSceneId}->{$toSceneId}";
    }
}