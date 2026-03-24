<?php

declare(strict_types=1);

namespace Kode\AiAgent\Video;

use Kode\AiAgent\Async\ParallelExecutor;
use Kode\AiAgent\Drama\{EnhancedScene, SceneVideo, TransitionManager, TransitionType, FrameVideoManager, FrameVideo};
use Kode\AiAgent\Log\LogManager;
use Psr\Log\LoggerInterface;

/**
 * 视频合成器 V3
 *
 * 支持完整短剧制作流程：
 * - 场景视频合成
 * - 转场效果
 * - 开场/结尾视频
 * - 多视频合并
 * - 字幕添加
 * - 背景音乐
 *
 * @package Kode\AiAgent\Video
 *
 * @example
 * ```php
 * $composer = new VideoComposerV3();
 *
 * // 添加场景视频
 * $composer->addSceneVideo($sceneVideo);
 *
 * // 添加转场
 * $composer->addTransition($fromId, $toId, TransitionType::FADE, 1);
 *
 * // 设置开场
 * $composer->setOpening($frameVideo);
 *
 * // 合成
 * $result = $composer->compose();
 * echo $result['output'];
 * ```
 */
final class VideoComposerV3
{
    private ?LoggerInterface $logger;
    private ParallelExecutor $executor;
    private array $config;
    private array $sceneVideos = [];
    private TransitionManager $transitionManager;
    private FrameVideoManager $frameManager;
    private array $backgroundMusic = [];
    private array $subtitle = [];

    public function __construct(
        ?LoggerInterface $logger = null,
        int $concurrency = 4,
        array $config = [],
    ) {
        $this->logger = $logger;
        $this->executor = new ParallelExecutor($concurrency, $config['enable_parallel'] ?? true);
        $this->config = array_merge([
            'output_dir' => 'var/drama/output',
            'temp_dir' => 'var/drama/temp',
            'default_transition' => 'fade',
            'default_duration' => 1,
            'resolution' => '1920x1080',
            'fps' => 30,
            'video_format' => 'mp4',
            'audio_format' => 'aac',
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'video_bitrate' => '5M',
            'audio_bitrate' => '192k',
        ], $config);

        $this->transitionManager = new TransitionManager();
        $this->frameManager = new FrameVideoManager();
    }

    /**
     * 添加场景视频
     */
    public function addSceneVideo(SceneVideo $sceneVideo): self
    {
        $this->sceneVideos[] = $sceneVideo;
        return $this;
    }

    /**
     * 批量添加场景视频
     */
    public function addSceneVideos(array $sceneVideos): self
    {
        foreach ($sceneVideos as $video) {
            $this->addSceneVideo($video);
        }
        return $this;
    }

    /**
     * 添加转场效果
     */
    public function addTransition(
        string $fromSceneId,
        string $toSceneId,
        TransitionType $type = TransitionType::FADE,
        int $duration = 1,
    ): self {
        $this->transitionManager->addTransition($fromSceneId, $toSceneId, $type, $duration);
        return $this;
    }

    /**
     * 设置开场视频
     */
    public function setOpening(FrameVideo $video): self
    {
        $this->frameManager->setOpening($video);
        return $this;
    }

    /**
     * 设置结尾视频
     */
    public function setClosing(FrameVideo $video): self
    {
        $this->frameManager->setClosing($video);
        return $this;
    }

    /**
     * 设置背景音乐
     */
    public function setBackgroundMusic(string $audioPath, float $volume = 0.3): self
    {
        $this->backgroundMusic = [
            'path' => $audioPath,
            'volume' => $volume,
        ];
        return $this;
    }

    /**
     * 设置字幕
     */
    public function setSubtitle(string $text, array $options = []): self
    {
        $this->subtitle = array_merge([
            'text' => $text,
            'position' => $options['position'] ?? 'bottom',
            'font_size' => $options['font_size'] ?? 24,
            'color' => $options['color'] ?? 'white',
        ], $options);

        return $this;
    }

    /**
     * 执行合成
     */
    public function compose(array $options = []): array
    {
        $startTime = microtime(true);

        $this->log('info', '开始视频合成', [
            'scenes' => count($this->sceneVideos),
            'has_opening' => $this->frameManager->hasOpening(),
            'has_closing' => $this->frameManager->hasClosing(),
        ]);

        usort($this->sceneVideos, fn($a, $b) => $a->order <=> $b->order);

        $outputPath = $this->generateOutputPath();

        if (count($this->sceneVideos) === 0 && !$this->frameManager->hasOpening() && !$this->frameManager->hasClosing()) {
            throw new \RuntimeException('没有可合成的视频');
        }

        if (count($this->sceneVideos) === 1) {
            $outputPath = $this->sceneVideos[0]->videoUrl;
        } else {
            $outputPath = $this->mergeVideos($options);
        }

        if ($this->frameManager->hasOpening() || $this->frameManager->hasClosing()) {
            $outputPath = $this->addFrameVideos($outputPath, $options);
        }

        if (!empty($this->backgroundMusic)) {
            $outputPath = $this->addBackgroundMusic($outputPath, $options);
        }

        if (!empty($this->subtitle)) {
            $outputPath = $this->addSubtitle($outputPath, $options);
        }

        $duration = microtime(true) - $startTime;

        $result = [
            'output' => $outputPath,
            'duration' => $duration,
            'scenes_count' => count($this->sceneVideos),
            'total_duration' => $this->calculateTotalDuration(),
            'transitions_count' => $this->transitionManager->count(),
            'has_opening' => $this->frameManager->hasOpening(),
            'has_closing' => $this->frameManager->hasClosing(),
        ];

        $this->log('info', '视频合成完成', $result);

        return $result;
    }

    /**
     * 并行合成（多场景同时处理）
     */
    public function composeParallel(array $options = []): array
    {
        $startTime = microtime(true);

        $this->log('info', '开始并行视频合成', [
            'scenes' => count($this->sceneVideos),
        ]);

        usort($this->sceneVideos, fn($a, $b) => $a->order <=> $b->order);

        $tasks = [];
        $tempFiles = [];

        foreach ($this->sceneVideos as $index => $scene) {
            $tempFile = $this->getTempPath("segment_{$index}.mp4");
            $tempFiles[] = $tempFile;

            $tasks[] = function () use ($scene, $tempFile, $options) {
                return $this->processVideoWithTransition($scene, $tempFile, $options);
            };
        }

        if (!empty($tasks)) {
            $this->executor->executeBatch($tasks);
        }

        $outputPath = $this->generateOutputPath();
        $this->concatenateVideos($tempFiles, $outputPath);

        if ($this->frameManager->hasOpening() || $this->frameManager->hasClosing()) {
            $outputPath = $this->addFrameVideos($outputPath, $options);
        }

        $duration = microtime(true) - $startTime;

        return [
            'output' => $outputPath,
            'duration' => $duration,
            'scenes_count' => count($this->sceneVideos),
            'parallel' => true,
        ];
    }

    /**
     * 处理单个视频（带转场预处理）
     */
    private function processVideoWithTransition(SceneVideo $scene, string $outputPath, array $options): string
    {
        $this->log('debug', '处理场景视频', ['scene_id' => $scene->sceneId]);

        $filters = [];

        if ($options['denoise'] ?? false) {
            $filters[] = 'hqdn3d';
        }

        if (!empty($filters)) {
            $filterStr = '-vf "' . implode(',', $filters) . '"';
            $command = sprintf(
                'ffmpeg -y -i %s %s %s 2>/dev/null',
                escapeshellarg($scene->videoUrl),
                $filterStr,
                escapeshellarg($outputPath)
            );
            exec($command, $outputLines, $returnCode);
        } else {
            copy($scene->videoUrl, $outputPath);
        }

        return $outputPath;
    }

    /**
     * 合并多个视频
     */
    private function mergeVideos(array $options = []): string
    {
        $outputPath = $this->generateOutputPath();
        $tempDir = $this->config['temp_dir'];

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $concatFile = $this->getTempPath('concat.txt');
        $content = [];

        foreach ($this->sceneVideos as $scene) {
            $content[] = "file '" . addslashes($scene->videoUrl) . "'";
        }

        file_put_contents($concatFile, implode("\n", $content));

        $command = sprintf(
            'ffmpeg -y -f concat -safe 0 -i %s -c copy %s 2>/dev/null',
            escapeshellarg($concatFile),
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        @unlink($concatFile);

        return $outputPath;
    }

    /**
     * 添加开场/结尾视频
     */
    private function addFrameVideos(string $inputPath, array $options = []): string
    {
        $outputPath = $this->generateOutputPath();
        $tempDir = $this->config['temp_dir'];

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $inputs = [];
        $filterComplex = '';
        $concatParts = [];

        $inputIndex = 0;

        if ($this->frameManager->hasOpening()) {
            $opening = $this->frameManager->getOpening();
            $inputs[] = '-i ' . escapeshellarg($opening->getVideoUrl());
            $concatParts[] = "[{$inputIndex}:v]";
            $inputIndex++;
        }

        $inputs[] = '-i ' . escapeshellarg($inputPath);
        $concatParts[] = "[{$inputIndex}:v]";
        $inputIndex++;

        if ($this->frameManager->hasClosing()) {
            $closing = $this->frameManager->getClosing();
            $inputs[] = '-i ' . escapeshellarg($closing->getVideoUrl());
            $concatParts[] = "[{$inputIndex}:v]";
        }

        $command = sprintf(
            'ffmpeg -y %s -filter_complex "%sconcat=n=%d:v=1:a=0[v]" -map "[v]" %s 2>/dev/null',
            implode(' ', $inputs),
            implode('', $concatParts),
            count($concatParts),
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 添加背景音乐
     */
    private function addBackgroundMusic(string $inputPath, array $options = []): string
    {
        $outputPath = $this->generateOutputPath();
        $volume = $this->backgroundMusic['volume'] ?? 0.3;

        $command = sprintf(
            'ffmpeg -y -i %s -i %s -filter_complex "[1:a]volume=%.2f[music];[0:a][music]amix=inputs=2:duration=longest[aout]" -map "[aout]" %s 2>/dev/null',
            escapeshellarg($inputPath),
            escapeshellarg($this->backgroundMusic['path']),
            $volume,
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 添加字幕
     */
    private function addSubtitle(string $inputPath, array $options = []): string
    {
        $outputPath = $this->generateOutputPath();

        $fontSize = $this->subtitle['font_size'] ?? 24;
        $color = $this->subtitle['color'] ?? 'white';
        $text = addslashes($this->subtitle['text']);

        $command = sprintf(
            'ffmpeg -y -i %s -vf "drawtext=text=\'%s\':fontsize=%d:fontcolor=%s:x=(w-text_w)/2:y=h-%d" %s 2>/dev/null',
            escapeshellarg($inputPath),
            $text,
            $fontSize,
            $color,
            $fontSize * 2,
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 连接多个视频
     */
    private function concatenateVideos(array $inputPaths, string $outputPath): void
    {
        $tempDir = $this->config['temp_dir'];

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $concatFile = $this->getTempPath('concat.txt');
        $content = [];

        foreach ($inputPaths as $path) {
            if (file_exists($path)) {
                $content[] = "file '" . addslashes($path) . "'";
            }
        }

        if (empty($content)) {
            return;
        }

        file_put_contents($concatFile, implode("\n", $content));

        $command = sprintf(
            'ffmpeg -y -f concat -safe 0 -i %s -c copy %s 2>/dev/null',
            escapeshellarg($concatFile),
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        @unlink($concatFile);

        foreach ($inputPaths as $path) {
            @unlink($path);
        }
    }

    /**
     * 计算总时长
     */
    private function calculateTotalDuration(): float
    {
        $duration = 0;

        foreach ($this->sceneVideos as $scene) {
            $duration += $scene->duration;
        }

        $duration += $this->frameManager->getTotalDuration();

        return $duration;
    }

    /**
     * 生成输出路径
     */
    private function generateOutputPath(): string
    {
        $outputDir = $this->config['output_dir'];

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        return sprintf(
            '%s/drama_%s.%s',
            $outputDir,
            date('Ymd-His') . '-' . bin2hex(random_bytes(4)),
            $this->config['video_format']
        );
    }

    /**
     * 获取临时文件路径
     */
    private function getTempPath(string $filename): string
    {
        $tempDir = $this->config['temp_dir'];

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        return $tempDir . '/' . $filename . '_' . bin2hex(random_bytes(4)) . '.mp4';
    }

    /**
     * 获取转场管理器
     */
    public function getTransitionManager(): TransitionManager
    {
        return $this->transitionManager;
    }

    /**
     * 获取帧视频管理器
     */
    public function getFrameManager(): FrameVideoManager
    {
        return $this->frameManager;
    }

    /**
     * 清除所有数据
     */
    public function clear(): self
    {
        $this->sceneVideos = [];
        $this->transitionManager->clear();
        $this->frameManager->clear();
        $this->backgroundMusic = [];
        $this->subtitle = [];
        return $this;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->$level("[VideoComposerV3] {$message}", $context);
        }

        LogManager::channel('video')->$level($message, $context);
    }
}