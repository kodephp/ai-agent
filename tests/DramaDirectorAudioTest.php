<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\AudioGateway\AudioGateway;
use Kode\AiAgent\Domain\Contract\TtsProviderInterface;
use Kode\AiAgent\Domain\Model\AudioResponse;
use Kode\AiAgent\Drama\Director\{DramaDirector, DramaSegment, ModelBinding, ScriptSplitter};
use Kode\AiAgent\VideoGateway\VideoGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * 漫剧导演：分段 CRUD + 每步模型绑定 + 配音（TTS）测试
 */
final class DramaDirectorAudioTest extends TestCase
{
    private function fakeAudioGateway(): AudioGateway
    {
        $gw = new AudioGateway(new NullLogger());
        $gw->addProvider(new class implements TtsProviderInterface {
            public function name(): string { return 'openai'; }
            public function model(): string { return 'gpt-4o-mini-tts'; }
            public function supportedVoices(): array { return ['alloy', 'nova']; }
            public function synthesize(string $text, array $options = []): AudioResponse
            {
                return new AudioResponse(
                    audios: ['/tmp/tts_' . md5($text) . '.mp3'],
                    voice: $options['voice'] ?? 'alloy',
                    model: 'gpt-4o-mini-tts',
                );
            }
            public function estimateCost(array $options = []): float { return 0.001; }
        });

        return $gw;
    }

    private function director(): DramaDirector
    {
        return (new DramaDirector(new VideoGateway(), [], new NullLogger()))
            ->setAudioGateway($this->fakeAudioGateway());
    }

    public function testGenerateProducesAudioForNarratedSegments(): void
    {
        $director = $this->director();

        // 使用 background_video 复用，避免调用真实视频网关；仅验证配音
        $result = $director->generate([
            ['background_video' => 'bg1.mp4', 'audio_text' => '第一幕的旁白'],
            ['background_video' => 'bg2.mp4', 'audio_text' => '第二幕的旁白', 'tts' => new ModelBinding(null, 'gpt-4o-mini-tts', 'nova')],
            ['background_video' => 'bg3.mp4'],
        ]);

        $segments = $director->segments();

        self::assertSame('generated', $segments[0]->audioStatus);
        self::assertSame('generated', $segments[1]->audioStatus);
        self::assertSame('none', $segments[2]->audioStatus);

        self::assertStringContainsString('tts_', $segments[0]->audioUrl);
        self::assertSame('reused', $segments[0]->status);
        self::assertSame('nova', $segments[1]->tts?->voice);
    }

    public function testAddInsertRemoveUpdateSegments(): void
    {
        $director = $this->director();
        $director->addSegment(['prompt' => '场景A', 'order' => 1]);
        $director->addSegment(['prompt' => '场景C', 'order' => 2]);

        self::assertCount(2, $director->segments());
        self::assertSame('场景A', $director->getSegment(0)->title);

        $inserted = $director->insertSegment(1, ['prompt' => '场景B']);
        self::assertCount(3, $director->segments());
        self::assertSame('场景B', $director->getSegment(1)->title);
        self::assertSame(2, $director->getSegment(1)->order);

        // order 重排
        self::assertSame(1, $director->getSegment(0)->order);
        self::assertSame(3, $director->getSegment(2)->order);

        // 按 ID 更新（ID 唯一，不与现有冲突）
        $director->updateSegment($inserted->id, ['prompt' => '场景B改', 'duration' => 8]);
        self::assertSame('场景B改', $director->getSegment(1)->prompt);
        self::assertSame(8, $director->getSegment(1)->duration);

        self::assertTrue($director->removeSegment(1));
        self::assertCount(2, $director->segments());
        self::assertNull($director->findSegment($inserted->id));
    }

    public function testRegenerateSegmentAudio(): void
    {
        $director = $this->director();
        $director->addSegment(['prompt' => '场景A', 'audio_text' => '原旁白']);

        $seg = $director->getSegment(0);
        self::assertSame('pending', $seg->audioStatus);

        $updated = $director->regenerateSegmentAudio(0, ['audio_text' => '新旁白']);
        self::assertSame('generated', $updated->audioStatus);
        self::assertSame('新旁白', $updated->audioText);
    }

    public function testToArrayRoundTrip(): void
    {
        $director = $this->director();
        $director->generate([
            ['background_video' => 'bg1.mp4', 'audio_text' => '旁白1', 'prompt' => 'P1'],
            ['background_video' => 'bg2.mp4', 'audio_text' => '旁白2'],
        ]);

        $data = $director->toArray();
        self::assertCount(2, $data);
        self::assertArrayHasKey('audio_text', $data[0]);
        self::assertArrayHasKey('tts', $data[0]);

        $restored = $this->director();
        $restored->setSegments($data);
        self::assertCount(2, $restored->segments());
        self::assertSame('旁白1', $restored->getSegment(0)->audioText);
    }

    public function testScriptSplitterParsesTtsDirectives(): void
    {
        $splitter = new ScriptSplitter();
        $segments = $splitter->split(
            "场景1\n@audio 这是旁白\n@voice nova\n@tts gpt-4o-mini-tts\n\n场景2\n@narration 第二段旁白",
            ['default_duration' => 5]
        );

        self::assertCount(2, $segments);
        self::assertSame('这是旁白', $segments[0]->audioText);
        self::assertNotNull($segments[0]->tts);
        self::assertSame('nova', $segments[0]->tts->voice);
        self::assertSame('gpt-4o-mini-tts', $segments[0]->tts->model);
        self::assertSame('第二段旁白', $segments[1]->audioText);
    }
}
