<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

use Kode\AiAgent\Domain\Model\AudioResponse;

/**
 * 语音合成（TTS）供应商接口
 *
 * 统一文字转语音供应商（OpenAI TTS、火山 COSYVoice、阿里 CosyVoice 等）的能力契约。
 * 统一音频网关（AudioGateway）基于音色 / 模型 / 成本在多个供应商之间自动路由。
 *
 * @package Kode\AiAgent\Domain\Contract
 *
 * @example
 * ```php
 * class MyTtsProvider implements TtsProviderInterface
 * {
 *     public function name(): string { return 'my-tts'; }
 *     public function model(): string { return 'my-model'; }
 *     public function supportedVoices(): array { return ['alloy', 'nova']; }
 *     public function synthesize(string $text, array $options = []): AudioResponse { ... }
 *     public function estimateCost(array $options = []): float { ... }
 * }
 * ```
 */
interface TtsProviderInterface
{
    /**
     * 供应商名称（用于路由与统计）
     */
    public function name(): string;

    /**
     * 当前使用的模型名称
     */
    public function model(): string;

    /**
     * 支持的音色列表
     *
     * @return array<int, string>
     */
    public function supportedVoices(): array;

    /**
     * 文字转语音
     *
     * @param string $text 待合成的文本（旁白 / 口播）
     * @param array<string, mixed> $options voice / instructions / model / speed / language 等
     */
    #[\NoDiscard]
    public function synthesize(string $text, array $options = []): AudioResponse;

    /**
     * 估算单次合成成本（美元）
     *
     * @param array<string, mixed> $options
     */
    public function estimateCost(array $options = []): float;
}
