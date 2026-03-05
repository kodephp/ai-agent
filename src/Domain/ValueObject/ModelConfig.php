<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\ValueObject;

/**
 * 模型配置值对象
 * 
 * 封装模型配置参数。
 * 
 * @package Kode\AiAgent\Domain\ValueObject
 * 
 * @example
 * ```php
 * $config = ModelConfig::create('gpt-4', temperature: 0.7);
 * ```
 */
readonly class ModelConfig
{
    public function __construct(
        public string $model = 'gpt-4o',
        public float $temperature = 0.7,
        public int $maxTokens = 4096,
        public float $topP = 1.0,
        public float $frequencyPenalty = 0.0,
        public float $presencePenalty = 0.0,
        public ?string $stop = null,
    ) {}

    /**
     * 创建配置
     */
    public static function create(string $model, array $options = []): self
    {
        return new self(
            model: $model,
            temperature: $options['temperature'] ?? 0.7,
            maxTokens: $options['max_tokens'] ?? 4096,
            topP: $options['top_p'] ?? 1.0,
            frequencyPenalty: $options['frequency_penalty'] ?? 0.0,
            presencePenalty: $options['presence_penalty'] ?? 0.0,
            stop: $options['stop'] ?? null,
        );
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        $result = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'top_p' => $this->topP,
            'frequency_penalty' => $this->frequencyPenalty,
            'presence_penalty' => $this->presencePenalty,
        ];

        if ($this->stop !== null) {
            $result['stop'] = $this->stop;
        }

        return $result;
    }

    /**
     * 创建新配置并修改指定字段
     */
    public function with(array $values): static
    {
        $data = get_object_vars($this);
        return new self(...array_merge($data, $values));
    }
}
