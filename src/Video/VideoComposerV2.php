<?php

declare(strict_types=1);

namespace Kode\AiAgent\Video;

use Kode\AiAgent\Async\{FiberPool, ParallelExecutor};
use Kode\AiAgent\Log\LogManager;
use Kode\AiAgent\Drama\SceneVideo;
use Psr\Log\LoggerInterface;

final class VideoComposerV2
{
    private ?LoggerInterface $logger;
    private ParallelExecutor $executor;
    private array $config;

    public function __construct(
        ?LoggerInterface $logger = null,
        int $concurrency = 4,
        array $config = [],
    ) {
        $this->logger = $logger;
        $this->executor = new ParallelExecutor($concurrency, $config['enable_parallel'] ?? true);
        $this->config = array_merge([
            'enable_parallel' => true,
            'concurrency' => $concurrency,
            'output_dir' => 'var/drama/output',
            'temp_dir' => 'var/drama/temp',
            'default_transition' => 'fade',
            'default_duration' => 1.0,
        ], $config);
    }

    public function compose(array $videos, array $options = []): string
    {
        if (empty($videos)) {
            throw new \InvalidArgumentException('No videos to compose');
        }

        $this->log('info', 'Starting video composition', [
            'video_count' => count($videos),
            'parallel_enabled' => $this->executor->isParallelEnabled(),
            'concurrency' => $this->executor->concurrency(),
        ]);

        $startTime = microtime(true);

        usort($videos, fn($a, $b) => $a->order <=> $b->order);

        $totalDuration = array_sum(array_map(fn($v) => $v->duration, $videos));

        $outputUrl = $this->generateOutputPath($options['format'] ?? 'mp4');

        $merger = new VideoMerger();

        foreach ($videos as $video) {
            $merger->addSegment($video->videoUrl, [
                'duration' => $video->duration,
                'volume' => $options['volume'] ?? 1.0,
                'fade_in' => $options['fade_in'] ?? 0,
                'fade_out' => $options['fade_out'] ?? 0,
            ]);
        }

        if (!empty($options['transitions'])) {
            foreach ($options['transitions'] as $transition) {
                $merger->addTransition(
                    $transition['type'] ?? 'fade',
                    $transition['duration'] ?? 1.0,
                    $transition['options'] ?? []
                );
            }
        }

        if (!empty($options['background_music'])) {
            $merger->addAudioTrack($options['background_music'], [
                'volume' => $options['music_volume'] ?? 0.3,
                'loop' => true,
            ]);
        }

        $merger->setOutputPath($outputUrl);
        $resultPath = $merger->merge();

        $duration = microtime(true) - $startTime;

        $this->log('info', 'Video composition completed', [
            'output_url' => $resultPath,
            'total_duration' => $totalDuration,
            'processing_time' => round($duration, 3),
        ]);

        return $resultPath;
    }

    public function composeParallel(array $videos, array $options = []): string
    {
        if (empty($videos)) {
            throw new \InvalidArgumentException('No videos to compose');
        }

        $this->log('info', 'Starting parallel video composition', [
            'video_count' => count($videos),
        ]);

        $startTime = microtime(true);

        usort($videos, fn($a, $b) => $a->order <=> $b->order);

        $segments = [];
        $tasks = [];

        foreach ($videos as $video) {
            $segments[] = [
                'scene_id' => $video->sceneId,
                'order' => $video->order,
                'duration' => $video->duration,
            ];

            $tasks[] = fn() => $this->processVideoSegment($video, $options);
        }

        $results = $this->executor->executeBatch($tasks);

        $outputUrl = $this->generateOutputPath($options['format'] ?? 'mp4');

        $merger = new VideoMerger();

        foreach ($results as $result) {
            if ($result !== null) {
                $merger->addSegment($result, [
                    'duration' => $result['duration'] ?? 10,
                ]);
            }
        }

        $merger->setOutputPath($outputUrl);
        $finalPath = $merger->merge();

        $duration = microtime(true) - $startTime;

        $this->log('info', 'Parallel composition completed', [
            'output_url' => $finalPath,
            'processing_time' => round($duration, 3),
        ]);

        return $finalPath;
    }

    public function concatenate(array $videoPaths, array $options = []): string
    {
        $this->log('info', 'Concatenating videos', ['count' => count($videoPaths)]);

        if (count($videoPaths) < 2) {
            return $videoPaths[0] ?? '';
        }

        $outputUrl = $this->generateOutputPath($options['format'] ?? 'mp4');

        $tempDir = $this->config['temp_dir'];
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $concatFile = $tempDir . '/concat_' . bin2hex(random_bytes(4)) . '.txt';

        $content = implode("\n", array_map(
            fn($path) => "file '" . addslashes($path) . "'",
            $videoPaths
        ));

        file_put_contents($concatFile, $content);

        $command = sprintf(
            'ffmpeg -y -f concat -safe 0 -i %s -c copy %s 2>/dev/null',
            escapeshellarg($concatFile),
            escapeshellarg($outputUrl)
        );

        exec($command, $outputLines, $returnCode);

        @unlink($concatFile);

        if ($returnCode !== 0) {
            $this->log('error', 'FFmpeg concatenation failed', [
                'command' => $command,
                'return_code' => $returnCode,
            ]);
        }

        return $outputUrl;
    }

    public function split(string $videoPath, float $chunkDuration, array $options = []): array
    {
        $this->log('info', 'Splitting video', [
            'path' => $videoPath,
            'chunk_duration' => $chunkDuration,
        ]);

        $outputDir = ($options['output_dir'] ?? $this->config['output_dir']) . '/split_' . date('Ymd-His');
        mkdir($outputDir, 0755, true);

        $command = sprintf(
            'ffmpeg -y -i %s -f segment -segment_time %d -c copy %s/segment_%%03d.mp4 2>/dev/null',
            escapeshellarg($videoPath),
            (int) $chunkDuration,
            escapeshellarg($outputDir)
        );

        exec($command, $output, $returnCode);

        $chunks = [];

        if ($returnCode === 0) {
            $files = glob($outputDir . '/segment_*.mp4');
            sort($files);

            foreach ($files as $index => $file) {
                $chunks[] = [
                    'path' => $file,
                    'index' => $index,
                    'duration' => $chunkDuration,
                ];
            }
        }

        return $chunks;
    }

    public function extractAudio(string $videoPath, array $options = []): string
    {
        $outputPath = str_replace('.mp4', '.mp3', $videoPath);

        if (isset($options['output'])) {
            $outputPath = $options['output'];
        }

        $command = sprintf(
            'ffmpeg -y -i %s -vn -acodec mp3 %s 2>/dev/null',
            escapeshellarg($videoPath),
            escapeshellarg($outputPath)
        );

        exec($command);

        return $outputPath;
    }

    public function addWatermark(string $videoPath, string $watermarkPath, array $options = []): string
    {
        $outputPath = str_replace('.mp4', '_watermarked.mp4', $videoPath);

        $position = $options['position'] ?? '右下';
        $opacity = $options['opacity'] ?? 0.3;
        $scale = $options['scale'] ?? 0.2;

        $positionFilter = match ($position) {
            '左上' => 'overlay=10:10',
            '右上' => 'overlay=main_w-overlay_w-10:10',
            '左下', '右下' => 'overlay=10:main_h-overlay_h-10',
            default => 'overlay=main_w-overlay_w-10:main_h-overlay_h-10',
        };

        $command = sprintf(
            'ffmpeg -y -i %s -i %s -filter_complex "[1:v]scale=iw*%.2f:ih*%.2f,format=rgba,colorchannelmixer=aa=%.2f[wm];[0:v][wm]%s" -c:a copy %s 2>/dev/null',
            escapeshellarg($videoPath),
            escapeshellarg($watermarkPath),
            $scale,
            $scale,
            $opacity,
            $positionFilter,
            escapeshellarg($outputPath)
        );

        exec($command);

        return $outputPath;
    }

    private function processVideoSegment(SceneVideo $video, array $options): array
    {
        $this->log('debug', 'Processing video segment', [
            'scene_id' => $video->sceneId,
            'order' => $video->order,
        ]);

        $filters = [];

        if ($options['denoise'] ?? false) {
            $filters[] = 'hqdn3d';
        }

        if ($options['stabilize'] ?? false) {
            $filters[] = 'deshake';
        }

        $filterStr = empty($filters) ? '' : '-vf "' . implode(',', $filters) . '"';

        return [
            'scene_id' => $video->sceneId,
            'order' => $video->order,
            'original_path' => $video->videoUrl,
            'processed_path' => $video->videoUrl,
            'duration' => $video->duration,
            'filters' => $filters,
        ];
    }

    private function generateOutputPath(string $format = 'mp4'): string
    {
        $outputDir = $this->config['output_dir'];

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        return sprintf(
            '%s/drama_%s.%s',
            $outputDir,
            date('Ymd-His') . '-' . bin2hex(random_bytes(4)),
            $format
        );
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->$level("[VideoComposerV2] {$message}", $context);
        }

        LogManager::channel('video')->$level($message, $context);
    }
}