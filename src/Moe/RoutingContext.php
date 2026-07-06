<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe;

/**
 * 路由上下文
 *
 * 描述一次路由请求的完整上下文，用于路由器决策。
 *
 * @package Kode\AiAgent\Moe
 */
final readonly class RoutingContext
{
    /**
     * @param string|null $capability 能力标签（如 'chat', 'code', 'vision'）
     * @param string|null $preferredPlatform 偏好平台
     * @param string|null $preferredModel 偏好模型
     * @param float|null $maxCost 单次最大成本（美元）
     * @param int|null $maxTokens 单次最大 Token 数
     * @param float $temperature 温度参数
     * @param bool $stream 是否流式
     * @param string $promptText 原始 Prompt 文本（用于 Token 效率分析）
     * @param array<string, mixed> $extra 额外上下文
     */
    public function __construct(
        public ?string $capability = null,
        public ?string $preferredPlatform = null,
        public ?string $preferredModel = null,
        public ?float $maxCost = null,
        public ?int $maxTokens = null,
        public float $temperature = 0.7,
        public bool $stream = false,
        public string $promptText = '',
        public array $extra = [],
    ) {}

    /**
     * 从 options 数组构造
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options, string $promptText = ''): self
    {
        return new self(
            capability: isset($options['capability']) ? (string) $options['capability'] : null,
            preferredPlatform: isset($options['preferred_platform']) ? (string) $options['preferred_platform'] : null,
            preferredModel: isset($options['preferred_model']) ? (string) $options['preferred_model'] : null,
            maxCost: isset($options['max_cost']) ? (float) $options['max_cost'] : null,
            maxTokens: isset($options['max_tokens']) ? (int) $options['max_tokens'] : null,
            temperature: (float) ($options['temperature'] ?? 0.7),
            stream: (bool) ($options['stream'] ?? false),
            promptText: $promptText,
            extra: array_diff_key($options, array_flip([
                'capability', 'preferred_platform', 'preferred_model',
                'max_cost', 'max_tokens', 'temperature', 'stream',
            ])),
        );
    }

    /**
     * 转换为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'capability' => $this->capability,
            'preferred_platform' => $this->preferredPlatform,
            'preferred_model' => $this->preferredModel,
            'max_cost' => $this->maxCost,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'stream' => $this->stream,
            'prompt_text' => $this->promptText,
        ];

        return array_filter($result, static fn($v) => $v !== null && $v !== '') + ['extra' => $this->extra];
    }
}
