<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Contract\VideoProviderInterface;
use Kode\AiAgent\Domain\Model\VideoResponse;
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\Drama\Director\{DramaDirector, DramaResult, DramaSegment, ModelBinding, ScriptSplitter};
use Kode\AiAgent\VideoGateway\VideoGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * 漫剧导演模块测试
 *
 * 覆盖：剧本拆分（文本 / 结构化 / 行内指令）、导演生成、单段重生成、
 * 背景视频复用、供应商失败回退、合成产出。
 */
final class DramaDirectorTest extends TestCase
{
    private function fakeProvider(string $name, bool $throw = false): VideoProviderInterface
    {
        return new class ($name, $throw) implements VideoProviderInterface {
            public function __construct(private string $n, private bool $fail) {}

            public function name(): string
            {
                return $this->n;
            }

            public function model(): string
            {
                return $this->n . '-model';
            }

            public function supportedCapabilities(): array
            {
                return [MultimodalCapability::TEXT_TO_VIDEO, MultimodalCapability::IMAGE_TO_VIDEO];
            }

            public function textToVideo(string $prompt, array $options = []): VideoResponse
            {
                if ($this->fail) {
                    throw new \RuntimeException('provider down: ' . $this->n);
                }

                return new VideoResponse(
                    videos: ['https://cdn.example.com/' . md5($prompt) . '.mp4'],
                    duration: (float) ($options['duration'] ?? 5),
                    model: $this->n . '-model',
                    requestId: uniqid('req-'),
                );
            }

            public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
            {
                if ($this->fail) {
                    throw new \RuntimeException('provider down: ' . $this->n);
                }

                return new VideoResponse(
                    videos: ['https://cdn.example.com/img-' . md5($image) . '.mp4'],
                    duration: (float) ($options['duration'] ?? 5),
                    model: $this->n . '-model',
                );
            }

            public function generateAvatar(string $text, array $options = []): VideoResponse
            {
                if ($this->fail) {
                    throw new \RuntimeException('provider down: ' . $this->n);
                }

                return new VideoResponse(videos: ['https://cdn.example.com/avatar.mp4'], model: $this->n . '-model');
            }

            public function getProgress(string $taskId): array
            {
                return ['task_id' => $taskId, 'progress' => 100];
            }

            public function estimateCost(array $options = []): float
            {
                return 0.01;
            }
        };
    }

    private function gatewayWithFallback(): VideoGateway
    {
        $gw = new VideoGateway('capability_aware', new NullLogger());
        $gw->addProvider($this->fakeProvider('bad', true), priority: 1);
        $gw->addProvider($this->fakeProvider('good'), priority: 2);

        return $gw;
    }

    /**
     * 合成器工厂桩：仅记录输入并返回一个假的输出路径（不依赖 ffmpeg）。
     */
    private function fakeComposerFactory(): callable
    {
        return function (?object $logger, int $concurrency, array $config): object {
            return new class {
                public int $sceneCount = 0;
                public ?string $music = null;
                /** @var array<int, array{from: string, to: string, type: string, duration: int}> */
                public array $transitions = [];

                public function addSceneVideos(array $sceneVideos): self
                {
                    $this->sceneCount = count($sceneVideos);

                    return $this;
                }

                public function addTransition(string $from, string $to, $type, float $duration = 1.0): self
                {
                    $this->transitions[] = [
                        'from' => $from,
                        'to' => $to,
                        'type' => $type instanceof \Kode\AiAgent\Drama\TransitionType ? $type->value : (string) $type,
                        'duration' => $duration,
                    ];

                    return $this;
                }

                public function setBackgroundMusic(string $music, float $volume): self
                {
                    $this->music = $music;

                    return $this;
                }

                public function compose(array $options = []): array
                {
                    return [
                        'output' => 'var/drama/output/final-fake.mp4',
                        'total_duration' => 12.0,
                        'transitions_count' => count($this->transitions),
                    ];
                }
            };
        };
    }

    public function testScriptSplitterTextWithInlineDirectives(): void
    {
        $text = "场景1：清晨的街道，一只猫在散步\n"
            . "@model seedance-2.5-pro\n"
            . "@provider good\n"
            . "@transition fade\n"
            . "@duration 6\n"
            . "@bg https://img/a.jpg\n\n"
            . "场景2：猫遇见一只狗\n"
            . "@model wanxiang\n"
            . "@transition dissolve";

        $segments = ScriptSplitter::split($text);

        self::assertCount(2, $segments);
        self::assertSame('seg-1', $segments[0]->id);
        self::assertStringContainsString('清晨的街道', $segments[0]->prompt);
        self::assertSame('seedance-2.5-pro', $segments[0]->model->model);
        self::assertSame('good', $segments[0]->model->provider);
        self::assertSame('fade', $segments[0]->transition->value);
        self::assertSame(6, $segments[0]->duration);
        self::assertSame('https://img/a.jpg', $segments[0]->backgroundImage);
        self::assertSame('wanxiang', $segments[1]->model->model);
        self::assertSame('dissolve', $segments[1]->transition->value);
    }

    public function testScriptSplitterStructuredArray(): void
    {
        $data = [
            ['title' => '开场', 'prompt' => '城市夜景', 'model' => 'seedance-2.5-pro'],
            ['prompt' => '海边日出', 'duration' => 8],
        ];

        $segments = ScriptSplitter::split($data);

        self::assertCount(2, $segments);
        self::assertSame('开场', $segments[0]->title);
        self::assertSame('seedance-2.5-pro', $segments[0]->model->model);
        self::assertSame(8, $segments[1]->duration);
        self::assertSame('seedance-2.5-pro', $segments[1]->model->model);
    }

    public function testDramaDirectorGenerate(): void
    {
        $gw = $this->gatewayWithFallback();
        $director = new DramaDirector($gw, ['default_model' => new ModelBinding('good', 'good-model')], new NullLogger(), $this->fakeComposerFactory());

        $result = $director->generate("场景1：一只猫\n@model good\n\n场景2：猫遇见狗", ['background_music' => 'bg.mp3']);

        self::assertInstanceOf(DramaResult::class, $result);
        self::assertSame('var/drama/output/final-fake.mp4', $result->finalVideo);
        self::assertCount(2, $result->segments);
        self::assertSame(2, $result->successCount());
        self::assertSame('https://cdn.example.com/', substr($result->segments[0]->generatedVideo, 0, 24));
        self::assertSame('generated', $result->segments[0]->status);
    }

    public function testRegenerateSegmentUpdatesPromptAndVideo(): void
    {
        $gw = $this->gatewayWithFallback();
        $director = new DramaDirector($gw, ['default_model' => new ModelBinding('good', 'good-model')], new NullLogger(), $this->fakeComposerFactory());

        $director->generate("场景1：一只猫\n\n场景2：猫遇见狗");
        $segment = $director->regenerateSegment(0, ['prompt' => '一只睡着的猫']);

        self::assertSame('一只睡着的猫', $segment->prompt);
        self::assertSame('regenerated', $segment->status);
        self::assertSame('https://cdn.example.com/', substr($segment->generatedVideo, 0, 24));
    }

    public function testBackgroundVideoReuse(): void
    {
        $gw = $this->gatewayWithFallback();
        $director = new DramaDirector($gw, ['default_model' => new ModelBinding('good', 'good-model')], new NullLogger(), $this->fakeComposerFactory());

        $result = $director->generate([
            ['prompt' => 'A', 'background_video' => 'https://cdn.example.com/bg1.mp4'],
            ['prompt' => 'B', 'background_video' => 'https://cdn.example.com/bg1.mp4'],
        ]);

        self::assertSame('reused', $result->segments[0]->status);
        self::assertSame('https://cdn.example.com/bg1.mp4', $result->segments[0]->generatedVideo);
        self::assertSame('reused', $result->segments[1]->status);
    }

    public function testProviderFailureFallsBack(): void
    {
        $gw = $this->gatewayWithFallback();
        $director = new DramaDirector($gw, [], new NullLogger(), $this->fakeComposerFactory());

        $result = $director->generate("场景1：一只猫\n\n场景2：猫遇见狗");

        self::assertSame(2, $result->successCount());
        self::assertSame('generated', $result->segments[0]->status);
    }

    public function testComposeThrowsWhenNoSuccess(): void
    {
        $gw = new VideoGateway('capability_aware', new NullLogger());
        $gw->addProvider($this->fakeProvider('bad', true), priority: 1);
        $director = new DramaDirector($gw, ['default_model' => new ModelBinding('bad', 'bad-model')], new NullLogger(), $this->fakeComposerFactory());

        $director->generate("场景1：一只猫\n\n场景2：猫遇见狗");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/没有可合成的片段视频/');
        $director->compose();
    }

    public function testDramaResultStats(): void
    {
        $gw = $this->gatewayWithFallback();
        $director = new DramaDirector($gw, ['default_model' => new ModelBinding('good', 'good-model')], new NullLogger(), $this->fakeComposerFactory());

        $result = $director->generate("场景1：一只猫\n\n场景2：猫遇见狗");
        $stats = $result->stats();

        self::assertSame(2, $stats['total']);
        self::assertSame(2, $stats['success']);
        self::assertSame(0, $stats['failed']);
        self::assertArrayHasKey('segments', $result->toArray());
    }
}
