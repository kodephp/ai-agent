<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Contract\VideoProviderInterface;
use Kode\AiAgent\Domain\Model\VideoResponse;
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\Drama\Director\{DramaDirector, DramaResult, ModelBinding};
use Kode\AiAgent\VideoGateway\VideoGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * DramaDirector 端到端集成测试（真实 VideoComposerV3 + 本地 ffmpeg）
 *
 * 用本地生成的测试片段冒充"已生成的视频"，验证：
 * 拆分 -> 逐段生成（网关返回本地片段）-> 真实转场合成 的完整链路。
 *
 * @requires function exec
 */
final class DramaDirectorIntegrationTest extends TestCase
{
    private string $tmpDir;
    /** @var array<int, string> */
    private array $clips = [];

    protected function setUp(): void
    {
        if (!function_exists('exec')) {
            self::markTestSkipped('exec 不可用');
        }
        exec('ffmpeg -version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('ffmpeg 不可用');
        }

        $this->tmpDir = sys_get_temp_dir() . '/drama_int_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);

        foreach (['red', 'green', 'blue'] as $i => $color) {
            $path = $this->tmpDir . '/clip' . $i . '.mp4';
            $command = sprintf(
                'ffmpeg -y -f lavfi -i color=c=%s:s=320x240:r=30:d=2 -f lavfi -i anullsrc=channel_layout=stereo:sample_rate=44100 -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest %s 2>/dev/null',
                $color,
                escapeshellarg($path)
            );
            exec($command, $o, $rc);
            if ($rc !== 0 || !is_file($path)) {
                self::markTestSkipped('生成测试片段失败');
            }
            $this->clips[] = $path;
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function gatewayReturningClips(): VideoGateway
    {
        $provider = new class ($this->clips) implements VideoProviderInterface {
            public function __construct(private array $clips) {}

            public function name(): string { return 'local'; }
            public function model(): string { return 'local-model'; }
            public function supportedCapabilities(): array { return [MultimodalCapability::TEXT_TO_VIDEO, MultimodalCapability::IMAGE_TO_VIDEO]; }
            public function textToVideo(string $prompt, array $options = []): VideoResponse
            {
                static $i = 0;
                $url = $this->clips[$i % count($this->clips)];
                $i++;

                return new VideoResponse(videos: [$url], duration: (float) ($options['duration'] ?? 2), model: 'local-model');
            }
            public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
            {
                return $this->textToVideo($prompt ?? '', $options);
            }
            public function generateAvatar(string $text, array $options = []): VideoResponse
            {
                return $this->textToVideo($text, $options);
            }
            public function getProgress(string $taskId): array { return []; }
            public function estimateCost(array $options = []): float { return 0.0; }
        };

        $gw = new VideoGateway('capability_aware', new NullLogger());
        $gw->addProvider($provider, priority: 1);

        return $gw;
    }

    public function testGenerateComposesRealVideoWithTransitions(): void
    {
        $director = new DramaDirector(
            $this->gatewayReturningClips(),
            ['default_model' => new ModelBinding('local', 'local-model')],
            new NullLogger()
        );

        $result = $director->generate(
            "场景1：红色的开始\n@transition fade\n\n场景2：绿色的发展\n@transition slide_left\n\n场景3：蓝色的结局",
            ['output_dir' => $this->tmpDir . '/out', 'transition_duration' => 0.5]
        );

        self::assertInstanceOf(DramaResult::class, $result);
        self::assertFileExists($result->finalVideo, '真实合成的最终视频应存在');
        self::assertSame(2, $result->metadata['transitions_count'] ?? null);
        self::assertSame(3, $result->successCount());
    }
}
