<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\AudioGateway\AudioGateway;
use Kode\AiAgent\Domain\Contract\TtsProviderInterface;
use Kode\AiAgent\Domain\Model\AudioResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * 统一音频网关测试（不触网）：路由 / 失败转移 / 按平台/模型选择
 */
final class AudioGatewayTest extends TestCase
{
    private function fakeProvider(string $name, string $model, bool $fail = false, int $priority = 100): TtsProviderInterface
    {
        return new class($name, $model, $fail) implements TtsProviderInterface {
            public function __construct(
                private string $n,
                private string $m,
                private bool $fail,
            ) {}

            public function name(): string
            {
                return $this->n;
            }

            public function model(): string
            {
                return $this->m;
            }

            public function supportedVoices(): array
            {
                return ['alloy', 'nova'];
            }

            public function synthesize(string $text, array $options = []): AudioResponse
            {
                if ($this->fail) {
                    throw new \RuntimeException('provider down');
                }

                return new AudioResponse(
                    audios: ['/tmp/' . $this->n . '_' . md5($text) . '.mp3'],
                    voice: $options['voice'] ?? 'alloy',
                    model: $this->m,
                );
            }

            public function estimateCost(array $options = []): float
            {
                return 0.001;
            }
        };
    }

    public function testRoutesToFirstAvailableProvider(): void
    {
        $gw = new AudioGateway(new NullLogger());
        $gw->addProvider($this->fakeProvider('openai', 'gpt-4o-mini-tts'), 100);
        $gw->addProvider($this->fakeProvider('volc', 'cosyvoice'), 200);

        $audio = $gw->textToSpeech('你好', ['voice' => 'nova']);

        self::assertTrue($audio->isSuccess());
        self::assertStringContainsString('openai', $audio->firstAudio());
        self::assertSame('nova', $audio->voice);
    }

    public function testFallsBackWhenPreferredFails(): void
    {
        $gw = new AudioGateway(new NullLogger());
        $gw->addProvider($this->fakeProvider('openai', 'gpt-4o-mini-tts', true), 100);
        $gw->addProvider($this->fakeProvider('volc', 'cosyvoice'), 200);

        $audio = $gw->textToSpeech('你好');

        self::assertTrue($audio->isSuccess());
        self::assertStringContainsString('volc', $audio->firstAudio());
    }

    public function testSelectsByPlatform(): void
    {
        $gw = new AudioGateway(new NullLogger());
        $gw->addProvider($this->fakeProvider('openai', 'gpt-4o-mini-tts'), 100);
        $gw->addProvider($this->fakeProvider('volc', 'cosyvoice'), 200);

        $audio = $gw->textToSpeech('你好', ['preferred_platform' => 'volc']);

        self::assertStringContainsString('volc', $audio->firstAudio());
    }

    public function testSelectsByModel(): void
    {
        $gw = new AudioGateway(new NullLogger());
        $gw->addProvider($this->fakeProvider('openai', 'gpt-4o-mini-tts'), 100);
        $gw->addProvider($this->fakeProvider('volc', 'cosyvoice'), 200);

        $audio = $gw->textToSpeech('你好', ['preferred_model' => 'cosyvoice']);

        self::assertStringContainsString('volc', $audio->firstAudio());
    }

    public function testThrowsWhenAllProvidersFail(): void
    {
        $gw = new AudioGateway(new NullLogger());
        $gw->addProvider($this->fakeProvider('openai', 'gpt-4o-mini-tts', true), 100);

        $this->expectException(\Kode\AiAgent\Exception\PlatformException::class);
        $gw->textToSpeech('你好');
    }

    public function testThrowsWhenNoProvider(): void
    {
        $gw = new AudioGateway(new NullLogger());

        $this->expectException(\Kode\AiAgent\Exception\PlatformException::class);
        $gw->textToSpeech('你好');
    }
}
