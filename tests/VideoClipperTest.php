<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Support\FFmpegEscaper;
use Kode\AiAgent\Video\ClipOperation;
use Kode\AiAgent\Video\ClipOperationConfig;
use Kode\AiAgent\Video\VideoClipper;
use PHPUnit\Framework\TestCase;

/**
 * VideoClipper / FFmpegEscaper 安全与健壮性测试
 *
 * 覆盖滤镜参数白名单、shell 命令注入回归、ffprobe 空输出兜底。
 *
 * @requires function exec
 * @requires function shell_exec
 */
final class VideoClipperTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/clipper_sec_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    private function ffmpegAvailable(): bool
    {
        exec('ffmpeg -version 2>/dev/null', $out, $code);

        return $code === 0;
    }

    private function makeClip(float $duration = 3.0): string
    {
        $path = $this->tmpDir . '/src.mp4';
        $command = sprintf(
            'ffmpeg -y -f lavfi -i color=c=red:s=320x240:r=30:d=%.2f -c:v libx264 -pix_fmt yuv420p %s 2>/dev/null',
            $duration,
            escapeshellarg($path)
        );
        exec($command, $out, $code);
        if ($code !== 0 || !is_file($path)) {
            self::fail('生成测试片段失败');
        }

        return $path;
    }

    public function testFilterBuildersRejectInjectionPayloads(): void
    {
        $pad = new ClipOperationConfig(ClipOperation::PAD, [
            'color' => 'black"; touch ' . $this->tmpDir . '/pwn1; echo "',
            'width' => '1920;id',
            'height' => '1080',
        ]);
        $filter = $pad->toFFmpegFilter();
        self::assertStringNotContainsString('"', $filter);
        self::assertStringNotContainsString(';', $filter);
        self::assertStringNotContainsString('`', $filter);
        self::assertStringStartsWith('pad=1920:1080:', $filter);
        self::assertStringEndsWith(':black', $filter);

        $crop = new ClipOperationConfig(ClipOperation::CROP, [
            'width' => '100,drawtext=text=evil',
            'height' => 'ih',
            'x' => 'iw/2-100',
            'y' => '0',
        ]);
        self::assertSame('crop=iw:ih:iw/2-100:0', $crop->toFFmpegFilter());

        $scale = new ClipOperationConfig(ClipOperation::SCALE, [
            'width' => '1); touch /tmp/x; (',
            'height' => -2,
        ]);
        self::assertSame('scale=-1:-2', $scale->toFFmpegFilter());

        $split = new ClipOperationConfig(ClipOperation::SPLIT, ['count' => '2; touch /tmp/x']);
        self::assertSame('split=2', $split->toFFmpegFilter());

        $speedZero = new ClipOperationConfig(ClipOperation::SPEED, ['speed' => 0]);
        self::assertSame('setpts=1*PTS', $speedZero->toFFmpegFilter());

        $speedText = new ClipOperationConfig(ClipOperation::SPEED, ['speed' => 'abc']);
        self::assertSame('setpts=1*PTS', $speedText->toFFmpegFilter());

        $delay = new ClipOperationConfig(ClipOperation::DELAY, ['delay' => '1000|touch']);
        self::assertSame('adelay=0|0', $delay->toFFmpegFilter());

        $rotate = new ClipOperationConfig(ClipOperation::ROTATE, ['angle' => '90; touch /tmp/x']);
        self::assertSame('transpose=1', $rotate->toFFmpegFilter());

        $cut = new ClipOperationConfig(ClipOperation::CUT, startTime: 10.5, endTime: 30.0);
        self::assertStringStartsWith('trim=start=10.5:duration=19.5', $cut->toFFmpegFilter());

        $reverse = new ClipOperationConfig(ClipOperation::REVERSE);
        self::assertSame('reverse', $reverse->toFFmpegFilter());
    }

    public function testFfmpegEscaperColorWhitelist(): void
    {
        self::assertSame('white', FFmpegEscaper::color('white'));
        self::assertSame('#ff0000', FFmpegEscaper::color('#ff0000'));
        self::assertSame('0x1e90ff', FFmpegEscaper::color('0x1e90ff'));
        self::assertSame('white@0.5', FFmpegEscaper::color('white@0.5'));
        self::assertSame('black', FFmpegEscaper::color('red"; touch /tmp/x;'));
        self::assertSame('black', FFmpegEscaper::color('red,text=1'));
        self::assertSame('black', FFmpegEscaper::color("red\nid"));
        self::assertSame('white', FFmpegEscaper::color('', 'white'));
    }

    public function testFfmpegEscaperQuoteShieldsSeparators(): void
    {
        self::assertSame("'hello'", FFmpegEscaper::quote('hello'));
        self::assertSame("'a\\'b'", FFmpegEscaper::quote("a'b"));
        self::assertSame("'a\\\\b'", FFmpegEscaper::quote('a\\b'));
        self::assertSame("'x,y;z=w'", FFmpegEscaper::quote('x,y;z=w'));
        self::assertSame("'it\\'s: a, test'", FFmpegEscaper::quote("it's: a, test"));
    }

    public function testSplitRejectsMaliciousFormat(): void
    {
        if (!$this->ffmpegAvailable()) {
            self::markTestSkipped('ffmpeg 不可用');
        }

        $clip = $this->makeClip();
        $marker = $this->tmpDir . '/pwned';

        $segments = (new VideoClipper())->split($clip, 1.0, [
            'output_dir' => $this->tmpDir . '/seg',
            'format' => 'mp4; touch ' . $marker,
        ]);

        self::assertNotEmpty($segments);
        foreach ($segments as $segment) {
            self::assertFileExists($segment['path']);
            self::assertStringEndsWith('.mp4', $segment['path']);
        }
        self::assertFileDoesNotExist($marker);
    }

    public function testPadRejectsMaliciousColor(): void
    {
        if (!$this->ffmpegAvailable()) {
            self::markTestSkipped('ffmpeg 不可用');
        }

        $clip = $this->makeClip();
        $marker = $this->tmpDir . '/pwned_pad';

        $output = (new VideoClipper())->pad($clip, 320, 240, 'black"; touch ' . $marker . '; echo "');

        self::assertFileExists($output);
        self::assertFileDoesNotExist($marker);
    }

    public function testExecuteWithInjectionParamsRunsClean(): void
    {
        if (!$this->ffmpegAvailable()) {
            self::markTestSkipped('ffmpeg 不可用');
        }

        $clip = $this->makeClip();
        $marker = $this->tmpDir . '/pwned_exec';

        $operations = [
            new ClipOperationConfig(ClipOperation::PAD, [
                'width' => 320,
                'height' => 240,
                'color' => 'black"; touch ' . $marker . '; echo "',
            ]),
            new ClipOperationConfig(ClipOperation::SCALE, [
                'width' => 160,
                'height' => 120,
            ]),
        ];

        $output = (new VideoClipper())->execute($clip, $operations, [
            'output' => $this->tmpDir . '/exec_out.mp4',
        ]);

        self::assertFileExists($output);
        self::assertFileDoesNotExist($marker);
    }

    public function testGetInfoMissingFileReturnsGracefulStructure(): void
    {
        $info = (new VideoClipper())->getInfo($this->tmpDir . '/missing.mp4');

        // ffprobe 对缺失文件可能输出空串（→ []）或占位 JSON（→ 全零结构），
        // 两种形态都必须优雅返回，不得抛 TypeError（strict_types 下 json_decode(null)）
        if ($info !== []) {
            self::assertSame(0.0, $info['duration']);
            self::assertSame('', $info['format']);
        }
    }
}
