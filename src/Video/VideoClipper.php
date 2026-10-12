<?php

declare(strict_types=1);

namespace Kode\AiAgent\Video;

use Kode\AiAgent\Support\FFmpegEscaper;

/**
 * 视频剪辑器
 *
 * 提供强大的视频剪辑功能，支持剪裁、分割、变速、旋转、裁剪等多种操作。
 *
 * @package Kode\AiAgent\Video
 *
 * @example
 * ```php
 * $clipper = new VideoClipper();
 *
 * // 剪裁视频
 * $clipper->cut('/path/to/video.mp4', 10, 30);
 *
 * // 变速播放
 * $clipper->setSpeed('/path/to/video.mp4', 2.0);
 *
 * // 旋转视频
 * $clipper->rotate('/path/to/video.mp4', 90);
 *
 * // 裁剪视频
 * $clipper->crop('/path/to/video.mp4', 1920, 1080, 0, 0);
 * ```
 */
final class VideoClipper
{
    /**
     * 剪裁视频
     *
     * @param string $inputPath 输入视频路径
     * @param float $startTime 起始时间（秒）
     * @param float $endTime 结束时间（秒）
     * @param array $options 选项
     * @return string 输出视频路径
     */
    public function cut(string $inputPath, float $startTime, float $endTime, array $options = []): string
    {
        $outputPath = $options['output'] ?? $this->generateOutputPath($inputPath);

        $command = sprintf(
            'ffmpeg -y -i %s -ss %.3f -to %.3f -c copy %s 2>/dev/null',
            escapeshellarg($inputPath),
            $startTime,
            $endTime,
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 剪裁视频（重新编码）
     */
    public function trim(string $inputPath, float $startTime, float $endTime, array $options = []): string
    {
        $outputPath = $options['output'] ?? $this->generateOutputPath($inputPath);

        $command = sprintf(
            'ffmpeg -y -i %s -ss %.3f -to %.3f -c:v libx264 -c:a aac %s 2>/dev/null',
            escapeshellarg($inputPath),
            $startTime,
            $endTime,
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 分割视频
     *
     * @param string $inputPath 输入视频路径
     * @param float $chunkDuration 每段时长（秒）
     * @param array $options 选项
     * @return array<int, array{path: string, index: int, start: float, end: float}> 分割结果
     */
    public function split(string $inputPath, float $chunkDuration, array $options = []): array
    {
        $outputDir = ($options['output_dir'] ?? dirname($inputPath) . '/split_' . date('Ymd-His'));
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // 容器格式白名单：直接拼进 shell 命令，仅放行简单容器扩展名
        $format = strtolower((string) ($options['format'] ?? 'mp4'));
        if (preg_match('/^[a-z0-9]{1,6}$/', $format) !== 1) {
            $format = 'mp4';
        }

        $command = sprintf(
            'ffmpeg -y -i %s -f segment -segment_time %d -c copy %s/segment_%%03d.%s 2>/dev/null',
            escapeshellarg($inputPath),
            (int) $chunkDuration,
            escapeshellarg($outputDir),
            $format
        );

        exec($command, $outputLines, $returnCode);

        $files = glob($outputDir . '/segment_*.' . $format);
        sort($files);

        $result = [];
        foreach ($files as $index => $file) {
            $result[] = [
                'path' => $file,
                'index' => $index,
                'start' => $index * $chunkDuration,
                'end' => ($index + 1) * $chunkDuration,
            ];
        }

        return $result;
    }

    /**
     * 变速播放
     *
     * @param string $inputPath 输入视频路径
     * @param float $speed 速度倍数 (>1 加速, <1 减速)
     * @param array $options 选项
     * @return string 输出视频路径
     */
    public function setSpeed(string $inputPath, float $speed, array $options = []): string
    {
        $outputPath = $options['output'] ?? $this->generateOutputPath($inputPath);
        $pts = 1.0 / $speed;

        $command = sprintf(
            'ffmpeg -y -i %s -filter_complex "[0:v]setpts=%.4f*PTS[v];[0:a]atempo=%.2f[a]" -map "[v]" -map "[a]" %s 2>/dev/null',
            escapeshellarg($inputPath),
            $pts,
            min(2.0, max(0.5, $speed)),
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 倒放视频
     */
    public function reverse(string $inputPath, array $options = []): string
    {
        $outputPath = $options['output'] ?? $this->generateOutputPath($inputPath);

        $command = sprintf(
            'ffmpeg -y -i %s -vf reverse -af areverse %s 2>/dev/null',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 旋转视频
     *
     * @param string $inputPath 输入视频路径
     * @param int $angle 旋转角度 (90, 180, 270)
     * @param array $options 选项
     * @return string 输出视频路径
     */
    public function rotate(string $inputPath, int $angle, array $options = []): string
    {
        $outputPath = $options['output'] ?? $this->generateOutputPath($inputPath);

        $transpose = match ($angle) {
            90 => 'transpose=1',
            180 => 'transpose=2,transpose=2',
            270 => 'transpose=2',
            default => 'transpose=1',
        };

        $command = sprintf(
            'ffmpeg -y -i %s -vf "%s" %s 2>/dev/null',
            escapeshellarg($inputPath),
            $transpose,
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 裁剪视频
     */
    public function crop(string $inputPath, int $width, int $height, int $x = 0, int $y = 0, array $options = []): string
    {
        $outputPath = $options['output'] ?? $this->generateOutputPath($inputPath);

        $command = sprintf(
            'ffmpeg -y -i %s -vf "crop=%d:%d:%d:%d" %s 2>/dev/null',
            escapeshellarg($inputPath),
            $width,
            $height,
            $x,
            $y,
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 缩放视频
     */
    public function scale(string $inputPath, int $width, int $height, array $options = []): string
    {
        $outputPath = $options['output'] ?? $this->generateOutputPath($inputPath);

        $command = sprintf(
            'ffmpeg -y -i %s -vf "scale=%d:%d" %s 2>/dev/null',
            escapeshellarg($inputPath),
            $width,
            $height,
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 添加黑边/填充
     */
    public function pad(string $inputPath, int $width, int $height, string $color = 'black', array $options = []): string
    {
        $outputPath = $options['output'] ?? $this->generateOutputPath($inputPath);

        $vf = sprintf(
            'pad=%d:%d:(ow-iw)/2:(oh-ih)/2:%s',
            $width,
            $height,
            FFmpegEscaper::color($color)
        );

        $command = sprintf(
            'ffmpeg -y -i %s -vf %s %s 2>/dev/null',
            escapeshellarg($inputPath),
            escapeshellarg($vf),
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 批量执行剪辑操作
     *
     * @param string $inputPath 输入视频路径
     * @param ClipOperationConfig[] $operations 剪辑操作配置
     * @param array $options 选项
     * @return string 输出视频路径
     */
    public function execute(string $inputPath, array $operations, array $options = []): string
    {
        $outputPath = $options['output'] ?? $this->generateOutputPath($inputPath);

        $filters = [];
        foreach ($operations as $op) {
            $filters[] = $op->toFFmpegFilter();
        }

        if (empty($filters)) {
            copy($inputPath, $outputPath);
            return $outputPath;
        }

        $filterComplex = implode(',', $filters);

        $command = sprintf(
            'ffmpeg -y -i %s -vf %s %s 2>/dev/null',
            escapeshellarg($inputPath),
            escapeshellarg($filterComplex),
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 获取视频信息
     */
    public function getInfo(string $videoPath): array
    {
        $command = sprintf(
            'ffprobe -v quiet -print_format json -show_format -show_streams %s 2>/dev/null',
            escapeshellarg($videoPath)
        );

        $output = (string) shell_exec($command);
        $info = json_decode($output, true);

        if ($info === null) {
            return [];
        }

        $videoStream = null;
        $audioStream = null;

        foreach ($info['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'video' && $videoStream === null) {
                $videoStream = $stream;
            } elseif ($stream['codec_type'] === 'audio' && $audioStream === null) {
                $audioStream = $stream;
            }
        }

        return [
            'duration' => (float) ($info['format']['duration'] ?? 0),
            'format' => $info['format']['format_name'] ?? '',
            'size' => (int) ($info['format']['size'] ?? 0),
            'bitrate' => (int) ($info['format']['bit_rate'] ?? 0),
            'video' => [
                'codec' => $videoStream['codec_name'] ?? '',
                'width' => (int) ($videoStream['width'] ?? 0),
                'height' => (int) ($videoStream['height'] ?? 0),
                'fps' => $this->parseFps($videoStream['r_frame_rate'] ?? '0/1'),
            ],
            'audio' => [
                'codec' => $audioStream['codec_name'] ?? '',
                'sample_rate' => (int) ($audioStream['sample_rate'] ?? 0),
                'channels' => (int) ($audioStream['channels'] ?? 0),
            ],
        ];
    }

    /**
     * 生成输出路径
     */
    private function generateOutputPath(string $inputPath): string
    {
        $dir = dirname($inputPath);
        $ext = pathinfo($inputPath, PATHINFO_EXTENSION);
        $name = pathinfo($inputPath, PATHINFO_FILENAME);

        return sprintf('%s/%s_edited_%s.%s', $dir, $name, date('Ymd-His'), $ext);
    }

    /**
     * 解析帧率
     */
    private function parseFps(string $fps): float
    {
        if (str_contains($fps, '/')) {
            [$num, $den] = explode('/', $fps);
            return $den > 0 ? (float) $num / (float) $den : 0;
        }
        return (float) $fps;
    }
}