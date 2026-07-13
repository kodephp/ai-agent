<?php

declare(strict_types=1);

namespace Kode\AiAgent\AudioGateway;

use Kode\AiAgent\Domain\Contract\TtsProviderInterface;
use Kode\AiAgent\Domain\Model\AudioResponse;
use Kode\AiAgent\Exception\PlatformException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 统一音频（TTS）网关
 *
 * 多供应商文字转语音的统一入口。后台分别配置各平台 Key，用户只感知一个网关，
 * 内部按模型 / 音色 / 成本自动选择最优供应商，并在失败时自动转移。
 *
 * 默认以 OpenAI gpt-4o-mini-tts（最像真人说话）作为首选供应商。
 *
 * @package Kode\AiAgent\AudioGateway
 *
 * @example
 * ```php
 * $gateway = new AudioGateway();
 * $gateway->addOpenAi(env('OPENAI_API_KEY'), ['voice' => 'nova']);
 *
 * $audio = $gateway->textToSpeech('欢迎来到万和水岸，今天讲一个关于猫的故事。', [
 *     'voice' => 'nova',
 * ]);
 * echo $audio->firstAudio();
 * ```
 */
final class AudioGateway
{
    /** @var array<int, array{provider: TtsProviderInterface, priority: int, weight: float}> */
    private array $providers = [];

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * 注册 TTS 供应商
     *
     * @param int $priority 优先级（数值越小越高）
     * @param float $weight 权重（当前按优先级路由，权重保留扩展）
     */
    public function addProvider(TtsProviderInterface $provider, int $priority = 100, float $weight = 1.0): self
    {
        $this->providers[] = [
            'provider' => $provider,
            'priority' => $priority,
            'weight' => $weight,
        ];

        usort($this->providers, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return $this;
    }

    /**
     * 便捷：注册 OpenAI TTS 供应商
     */
    public function addOpenAi(string $apiKey, array $options = [], int $priority = 100): self
    {
        return $this->addProvider(new Provider\OpenAiTtsProvider($apiKey, $options), $priority);
    }

    /**
     * 文字转语音（自动选最优供应商，失败转移）
     *
     * @param array<string, mixed> $options voice / instructions / model / speed 等
     */
    #[\NoDiscard]
    public function textToSpeech(string $text, array $options = []): AudioResponse
    {
        if ($this->providers === []) {
            throw new PlatformException('音频网关未注册任何 TTS 供应商');
        }

        $preferred = $this->selectProvider($options);

        foreach ($this->providers as $entry) {
            $provider = $entry['provider'];

            if ($preferred !== null && $provider !== $preferred) {
                continue;
            }

            try {
                $this->log('info', '音频网关：合成', ['provider' => $provider->name(), 'voice' => $options['voice'] ?? null]);
                $response = $provider->synthesize($text, $options);

                if ($response->isSuccess() && $response->firstAudio() !== '') {
                    return $response;
                }
            } catch (\Throwable $e) {
                $this->log('warning', '音频网关：供应商失败，转移', [
                    'provider' => $provider->name(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new PlatformException('所有 TTS 供应商均不可用');
    }

    /**
     * 根据选项选择首选供应商（preferred_platform / preferred_model）
     */
    private function selectProvider(array $options): ?TtsProviderInterface
    {
        $platform = $options['preferred_platform'] ?? $options['platform'] ?? null;
        $model = $options['preferred_model'] ?? $options['model'] ?? null;

        if ($platform === null && $model === null) {
            return null;
        }

        foreach ($this->providers as $entry) {
            $provider = $entry['provider'];
            if ($platform !== null && $provider->name() === $platform) {
                return $provider;
            }
            if ($model !== null && $provider->model() === $model) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @return array<int, TtsProviderInterface>
     */
    public function providers(): array
    {
        return array_map(static fn($e) => $e['provider'], $this->providers);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger->$level("[AudioGateway] {$message}", $context);
    }
}
