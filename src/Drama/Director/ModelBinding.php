<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama\Director;

/**
 * 模型绑定
 *
 * 为单个分镜（片段）绑定具体的生成模型 / 供应商，
 * 便于后续升级时替换为更优模型（如 Seedance 2.5 → 3.0）。
 *
 * @package Kode\AiAgent\Drama\Director
 */
final class ModelBinding
{
    public function __construct(
        public ?string $provider = null,
        public ?string $model = null,
    ) {}

    /**
     * 是否绑定了具体模型
     */
    public function hasBinding(): bool
    {
        return $this->provider !== null || $this->model !== null;
    }

    /**
     * 转换为统一视频网关的路由选项
     *
     * @return array<string, mixed>
     */
    public function toOptions(): array
    {
        $options = [];
        if ($this->model !== null) {
            $options['preferred_model'] = $this->model;
        }
        if ($this->provider !== null) {
            $options['preferred_platform'] = $this->provider;
        }
        return $options;
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            provider: $data['provider'] ?? null,
            model: $data['model'] ?? null,
        );
    }
}
