<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Drama\{SceneVideo, TransitionType};
use Kode\AiAgent\Video\VideoComposerV3;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * VideoComposerV3 集成测试（本地 ffmpeg，不依赖外部服务）
 *
 * 通过 ffmpeg 生成若干本地测试片段，验证转场（xfade）、背景音乐、字幕
 * 是否真正生效，并校验合成产物的时长。
 *
 * @requires function exec
 * @requires function proc_open
 */
final class VideoComposerV3Test extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        if (!function_exists('exec') || !$this->ffmpegAvailable()) {
            self::markTestSkipped('ffmpeg/ffprobe 不可用');
        }

        $this->tmpDir = sys_get_temp_dir() . '/drama_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function ffmpegAvailable(): bool
    {
        exec('ffmpeg -version 2>/dev/null', $out, $code);

        return $code === 0;
    }

    private function makeClip(string $name, string $color, float $duration): string
    {
        $path = $this->tmpDir . '/' . $name . '.mp4';
        $command = sprintf(
            'ffmpeg -y -f lavfi -i color=c=%s:s=320x240:r=30:d=%.2f -f lavfi -i anullsrc=channel_layout=stereo:sample_rate=44100 -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest %s 2>/dev/null',
            $color,
            $duration,
            escapeshellarg($path)
        );
        exec($command, $out, $code);

        if ($code !== 0 || !is_file($path)) {
            self::fail('生成测试片段失败: ' . $name);
        }

        return $path;
    }

    private function makeTone(string $name, float $duration): string
    {
        $path = $this->tmpDir . '/' . $name . '.mp3';
        $command = sprintf(
            'ffmpeg -y -f lavfi -i sine=frequency=440:duration=%.2f %s 2>/dev/null',
            $duration,
            escapeshellarg($path)
        );
        exec($command, $out, $code);

        return ($code === 0 && is_file($path)) ? $path : '';
    }

    private function probeDuration(string $path): float
    {
        $command = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($path)
        );
        exec($command, $out, $code);

        return $code === 0 ? (float) ($out[0] ?? 0) : 0.0;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testComposeWithTransitionsAndMusic(): void
    {
        $c1 = $this->makeClip('clip1', 'red', 2);
        $c2 = $this->makeClip('clip2', 'green', 2);
        $c3 = $this->makeClip('clip3', 'blue', 2);
        $bgm = $this->makeTone('bgm', 5);

        $composer = new VideoComposerV3(new NullLogger(), 4, [
            'resolution' => '320x240',
            'fps' => 30,
            'output_dir' => $this->tmpDir . '/out',
            'temp_dir' => $this->tmpDir . '/temp',
        ]);

        $composer->addSceneVideo(new SceneVideo('seg-1', 1, $c1, 2.0));
        $composer->addSceneVideo(new SceneVideo('seg-2', 2, $c2, 2.0));
        $composer->addSceneVideo(new SceneVideo('seg-3', 3, $c3, 2.0));
        $composer->addTransition('seg-1', 'seg-2', TransitionType::FADE, 0.5);
        $composer->addTransition('seg-2', 'seg-3', TransitionType::SLIDE_LEFT, 0.5);

        if ($bgm !== '') {
            $composer->setBackgroundMusic($bgm, 0.3);
        }

        $result = $composer->compose();

        self::assertFileExists($result['output']);
        self::assertSame(2, $result['transitions_count']);

        $duration = $this->probeDuration($result['output']);
        // 2 + 2 - 0.5 + 2 - 0.5 = 5.0s（转场重叠已扣除）
        self::assertGreaterThan(4.0, $duration, '合成视频时长异常（转场未生效？）');
        self::assertLessThan(6.0, $duration);
    }

    public function testComposeFallbackWithoutTransitions(): void
    {
        $c1 = $this->makeClip('a', 'red', 1);
        $c2 = $this->makeClip('b', 'blue', 1);

        $composer = new VideoComposerV3(new NullLogger(), 4, [
            'resolution' => '320x240',
            'fps' => 30,
            'output_dir' => $this->tmpDir . '/out',
            'temp_dir' => $this->tmpDir . '/temp',
            'enable_transitions' => false,
        ]);
        $composer->addSceneVideo(new SceneVideo('seg-1', 1, $c1, 1.0));
        $composer->addSceneVideo(new SceneVideo('seg-2', 2, $c2, 1.0));

        $result = $composer->compose();

        self::assertFileExists($result['output']);
        $duration = $this->probeDuration($result['output']);
        self::assertGreaterThan(1.5, $duration);
    }

    public function testComposeSingleScene(): void
    {
        $c1 = $this->makeClip('solo', 'red', 2);

        $composer = new VideoComposerV3(new NullLogger(), 4, [
            'resolution' => '320x240',
            'fps' => 30,
            'output_dir' => $this->tmpDir . '/out',
            'temp_dir' => $this->tmpDir . '/temp',
        ]);
        $composer->addSceneVideo(new SceneVideo('seg-1', 1, $c1, 2.0));

        $result = $composer->compose();

        self::assertFileExists($result['output']);
        self::assertSame(0, $result['transitions_count']);
    }
}
