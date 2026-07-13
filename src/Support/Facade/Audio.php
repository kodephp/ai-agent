<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support\Facade;

use Kode\AiAgent\AudioGateway\AudioGateway;
use Kode\AiAgent\AudioGateway\Provider\OpenAiTtsProvider;
use Kode\AiAgent\Domain\Model\AudioResponse;
use Kode\Context\Context as KodeContext;
use Kode\Facade\Facade;

/**
 * 统一音频（TTS）网关门面
 *
 * 提供简洁的静态调用接口访问 AudioGateway，
 * 默认内置 OpenAI TTS（gpt-4o-mini-tts，最接近真人）供应商。
 *
 * @package Kode\AiAgent\Support\Facade
 *
 * @example
 * ```php
 * // 配置供应商（可选，默认已内置 OpenAI TTS）
 * Audio::addOpenAi(env('OPENAI_API_KEY'), ['model' => 'gpt-4o-mini-tts']);
 *
 * // 文本转语音（返回 AudioResponse）
 * $audio = Audio::tts('欢迎使用万和水岸智能漫剧', ['voice' => 'alloy']);
 * echo $audio->url;
 * ```
 *
 * @method static AudioResponse tts(string $text, array $options = [])
 * @method static AudioResponse textToSpeech(string $text, array $options = [])
 * @method static self addProvider(\Kode\AiAgent\Domain\Contract\TtsProviderInterface $provider, int $priority = 100)
 * @method static self addOpenAi(string $apiKey, array $options = [], int $priority = 100)
 * @method static AudioGateway gateway()
 * @method static void reset()
 */
final class Audio extends Facade
{
    private const CONTEXT_KEY = 'ai_agent.audio.gateway';

    private static ?AudioGateway $default = null;

    protected static function id(): string
    {
        return 'audio';
    }

    public static function getInstance(): object
    {
        return new self();
    }

    public function gateway(): AudioGateway
    {
        $gateway = KodeContext::get(self::CONTEXT_KEY);
        if ($gateway instanceof AudioGateway) {
            return $gateway;
        }

        if (self::$default === null) {
            $gateway = new AudioGateway();
            if (($key = (string) getenv('OPENAI_API_KEY')) !== '') {
                $gateway->addOpenAi($key, [], 100);
            }
            self::$default = $gateway;
        }

        return self::$default;
    }

    #[\NoDiscard]
    public function tts(string $text, array $options = []): AudioResponse
    {
        return self::gateway()->textToSpeech($text, $options);
    }

    #[\NoDiscard]
    public function textToSpeech(string $text, array $options = []): AudioResponse
    {
        return self::gateway()->textToSpeech($text, $options);
    }

    public function addProvider(\Kode\AiAgent\Domain\Contract\TtsProviderInterface $provider, int $priority = 100): self
    {
        self::gateway()->addProvider($provider, $priority);
        return $this;
    }

    public function addOpenAi(string $apiKey, array $options = [], int $priority = 100): self
    {
        self::gateway()->addOpenAi($apiKey, $options, $priority);
        return $this;
    }

    /**
     * 设置自定义网关
     */
    public function setGateway(AudioGateway $gateway): void
    {
        KodeContext::set(self::CONTEXT_KEY, $gateway);
        self::$default = $gateway;
    }

    public function reset(): void
    {
        KodeContext::delete(self::CONTEXT_KEY);
        self::$default = null;
    }
}
