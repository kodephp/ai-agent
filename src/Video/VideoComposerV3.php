<?php

declare(strict_types=1);

namespace Kode\AiAgent\Video;

use Kode\AiAgent\Drama\{FrameVideo, SceneVideo, TransitionEffect, TransitionManager, TransitionType};
use Kode\AiAgent\Log\LogManager;
use Psr\Log\LoggerInterface;

/**
 * 视频合成器 V3（本地 ffmpeg 合成，不依赖外部服务）
 *
 * 支持完整短剧制作流程：
 * - 场景视频合成（按 order 排序）
 * - 转场效果（xfade / acrossfade，依据 TransitionManager 中每段之间的转场）
 * - 开场 / 结尾视频（前后拼接）
 * - 背景音乐（amix 混音）
 * - 字幕（drawtext）
 *
 * 所有输入在合成前会被归一化（统一分辨率 / 帧率 / 像素格式，并保证存在音轨），
 * 因此即便上游生成的片段参数不一致也能稳定合成。
 *
 * 依赖本地 ffmpeg / ffprobe（无需联网或第三方 API）。
 *
 * @package Kode\AiAgent\Video
 *
 * @example
 * ```php
 * $composer = new VideoComposerV3();
 * $composer->addSceneVideo(new SceneVideo('seg-1', 1, $url1, 5));
 * $composer->addSceneVideo(new SceneVideo('seg-2', 2, $url2, 5));
 * $composer->addTransition('seg-1', 'seg-2', TransitionType::FADE, 1);
 * $composer->setBackgroundMusic('/path/bgm.mp3', 0.3);
 * $result = $composer->compose();
 * echo $result['output'];
 * ```
 */
final class VideoComposerV3
{
    private ?LoggerInterface $logger;
    private array $config;
    private array $sceneVideos = [];
    private TransitionManager $transitionManager;
    private \Kode\AiAgent\Drama\FrameVideoManager $frameManager;
    private array $backgroundMusic = [];
    private array $subtitle = [];

    public function __construct(
        ?LoggerInterface $logger = null,
        int $concurrency = 4,
        array $config = [],
    ) {
        $this->logger = $logger;
        $this->config = array_merge([
            'output_dir' => 'var/drama/output',
            'temp_dir' => 'var/drama/temp',
            'default_transition' => 'fade',
            'default_duration' => 1,
            'resolution' => '1920x1080',
            'fps' => 30,
            'video_format' => 'mp4',
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'video_bitrate' => '5M',
            'audio_bitrate' => '192k',
            'preset' => 'veryfast',
            'enable_transitions' => true,
            'subtitle_font' => null,
        ], $config);

        $this->config['concurrency'] = $concurrency;

        $this->transitionManager = new TransitionManager();

        // FrameVideoManager 与 FrameVideo 同文件声明，先触发其加载以注册 FrameVideoManager 类
        class_exists(\Kode\AiAgent\Drama\FrameVideo::class);

        $this->frameManager = new \Kode\AiAgent\Drama\FrameVideoManager();
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
     * 添加转场效果（fromSceneId -> toSceneId 之间的转场）
     */
    public function addTransition(
        string $fromSceneId,
        string $toSceneId,
        TransitionType $type = TransitionType::FADE,
        float $duration = 1,
    ): self {
        $this->transitionManager->addTransition($fromSceneId, $toSceneId, $type, $duration);

        return $this;
    }

    /**
     * 设置开场视频
     */
    public function setOpening(\Kode\AiAgent\Drama\FrameVideo $video): self
    {
        $this->frameManager->setOpening($video);

        return $this;
    }

    /**
     * 设置结尾视频
     */
    public function setClosing(\Kode\AiAgent\Drama\FrameVideo $video): self
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
     * 设置字幕（需配置 subtitle_font 才会真正绘制，否则跳过并记录警告）
     */
    public function setSubtitle(string $text, array $options = []): self
    {
        $this->subtitle = array_merge([
            'text' => $text,
            'position' => $options['position'] ?? 'bottom',
            'font_size' => $options['font_size'] ?? 36,
            'color' => $options['color'] ?? 'white',
        ], $options);

        return $this;
    }

    /**
     * 执行合成
     *
     * 流程：转场合成（xfade） -> 拼接开场/结尾 -> 混背景音乐 -> 绘制字幕
     */
    public function compose(array $options = []): array
    {
        $startTime = microtime(true);

        usort($this->sceneVideos, fn(SceneVideo $a, SceneVideo $b) => $a->order <=> $b->order);

        $this->log('info', '开始视频合成', [
            'scenes' => count($this->sceneVideos),
            'has_opening' => $this->frameManager->hasOpening(),
            'has_closing' => $this->frameManager->hasClosing(),
            'transitions' => $this->transitionManager->count(),
        ]);

        if (count($this->sceneVideos) === 0 && !$this->frameManager->hasOpening() && !$this->frameManager->hasClosing()) {
            throw new \RuntimeException('没有可合成的视频');
        }

        // 1) 转场合成（场景之间）
        $base = $this->buildSceneComposition($options);

        // 2) 拼接开场 / 结尾
        if ($this->frameManager->hasOpening() || $this->frameManager->hasClosing()) {
            $base = $this->addFrameVideos($base, $options);
        }

        // 3) 背景音乐
        if (!empty($this->backgroundMusic)) {
            $base = $this->addBackgroundMusic($base, $options);
        }

        // 4) 字幕
        if (!empty($this->subtitle)) {
            $base = $this->addSubtitle($base, $options);
        }

        $result = [
            'output' => $base,
            'duration' => microtime(true) - $startTime,
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
     * 并行合成（保留原有 concat 行为，适用于无需转场的大批片段）
     */
    public function composeParallel(array $options = []): array
    {
        $startTime = microtime(true);

        usort($this->sceneVideos, fn(SceneVideo $a, SceneVideo $b) => $a->order <=> $b->order);

        $this->log('info', '开始并行视频合成', [
            'scenes' => count($this->sceneVideos),
        ]);

        if (count($this->sceneVideos) === 0) {
            throw new \RuntimeException('没有可合成的视频');
        }

        $outputPath = $this->generateOutputPath();
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

        if ($this->frameManager->hasOpening() || $this->frameManager->hasClosing()) {
            $outputPath = $this->addFrameVideos($outputPath, $options);
        }

        if (!empty($this->backgroundMusic)) {
            $outputPath = $this->addBackgroundMusic($outputPath, $options);
        }

        return [
            'output' => $outputPath,
            'duration' => microtime(true) - $startTime,
            'scenes_count' => count($this->sceneVideos),
            'parallel' => true,
        ];
    }

    /**
     * 构建场景之间的转场合成（xfade / acrossfade）
     *
     * 返回合成后的本地视频路径（已含音轨）。
     */
    private function buildSceneComposition(array $options): string
    {
        if (count($this->sceneVideos) === 0) {
            if ($this->frameManager->hasOpening()) {
                return $this->normalizeInput($this->frameManager->getOpening()->getVideoUrl(), 0.0);
            }

            throw new \RuntimeException('没有可合成的视频');
        }

        $useTransitions = ($options['enable_transitions'] ?? $this->config['enable_transitions']) && count($this->sceneVideos) > 1;

        // 归一化所有输入，统一分辨率 / 帧率 / 像素格式 / 音轨
        $normalized = [];
        $tempFiles = [];
        foreach ($this->sceneVideos as $scene) {
            $path = $this->normalizeInput($scene->videoUrl, (float) $scene->duration);
            if ($path !== $scene->videoUrl) {
                $tempFiles[] = $path;
            }
            $normalized[] = [
                'path' => $path,
                'duration' => (float) $scene->duration,
                'id' => $scene->sceneId,
            ];
        }

        $inputs = [];
        foreach ($normalized as $n) {
            $inputs[] = '-i ' . escapeshellarg($n['path']);
        }

        $filters = [];
        $curV = '0:v';
        $curA = '0:a';
        $mergedDur = $normalized[0]['duration'];

        if ($useTransitions) {
            for ($i = 1; $i < count($normalized); $i++) {
                $prev = $normalized[$i - 1];
                $cur = $normalized[$i];

                $effect = $this->transitionManager->getTransition($prev['id'], $cur['id']);
                $type = $effect instanceof \Kode\AiAgent\Drama\TransitionEffect ? $effect->type : TransitionType::FADE;
                $tau = $effect !== null ? (float) $effect->duration : (float) $this->config['default_duration'];

                $tau = min($tau, $prev['duration'] * 0.9, $cur['duration'] * 0.9);
                if ($tau <= 0) {
                    $tau = 0.3;
                }

                $xfade = $this->mapXfade($type);
                $offset = max(0.0, $mergedDur - $tau);

                $outV = 'v' . $i;
                $outA = 'a' . $i;

                $filters[] = sprintf(
                    '[%s][%d:v]xfade=transition=%s:duration=%.3f:offset=%.3f[%s]',
                    $curV,
                    $i,
                    $xfade,
                    $tau,
                    $offset,
                    $outV
                );
                $filters[] = sprintf(
                    '[%s][%d:a]acrossfade=duration=%.3f[%s]',
                    $curA,
                    $i,
                    $tau,
                    $outA
                );

                $curV = $outV;
                $curA = $outA;
                $mergedDur = $mergedDur + $cur['duration'] - $tau;
            }
        } elseif (count($normalized) > 1) {
            // 无转场：先普通拼接为临时基础文件，再走统一的最终导出
            $base = $this->plainConcat($normalized);
            $inputs = ['-i ' . escapeshellarg($base)];
            $curV = '0:v';
            $curA = '0:a';
        }

        $output = $this->generateOutputPath();
        if ($filters !== []) {
            $filterArg = '-filter_complex ' . escapeshellarg(implode(';', $filters));
            $mapV = '-map "[%s]"';
            $mapA = '-map "[%s]"';
        } else {
            $filterArg = '';
            $mapV = '-map 0:v';
            $mapA = '-map 0:a';
        }

        $command = sprintf(
            'ffmpeg -y %s %s %s %s -c:v %s -preset %s -pix_fmt yuv420p -c:a %s %s 2>/dev/null',
            implode(' ', $inputs),
            $filterArg,
            $mapV,
            $mapA,
            escapeshellarg($this->config['video_codec']),
            escapeshellarg($this->config['preset']),
            escapeshellarg($this->config['audio_codec']),
            escapeshellarg($output)
        );
        $command = sprintf($command, $curV, $curA);

        exec($command, $outputLines, $returnCode);

        // 转场合成失败（如 ffmpeg 不支持 xfade）时回退到普通拼接
        if ($returnCode !== 0 || !is_file($output)) {
            $this->log('warning', '转场合成失败，回退到普通拼接', ['command' => $command]);
            $output = $this->plainConcat($normalized);
        }

        $this->cleanup($tempFiles);

        return $output;
    }

    /**
     * 普通拼接（无转场），用于回退
     *
     * @param array<int, array{path: string, duration: float, id: string}> $normalized
     */
    private function plainConcat(array $normalized): string
    {
        if (count($normalized) === 1) {
            $output = $this->generateOutputPath();
            if (is_file($normalized[0]['path']) && copy($normalized[0]['path'], $output)) {
                return $output;
            }

            return $normalized[0]['path'];
        }

        $concatFile = $this->getTempPath('concat.txt');
        $content = [];
        foreach ($normalized as $n) {
            $content[] = "file '" . addslashes($n['path']) . "'";
        }
        file_put_contents($concatFile, implode("\n", $content));

        $output = $this->generateOutputPath();
        $command = sprintf(
            'ffmpeg -y -f concat -safe 0 -i %s -c copy %s 2>/dev/null',
            escapeshellarg($concatFile),
            escapeshellarg($output)
        );
        exec($command, $outputLines, $returnCode);
        @unlink($concatFile);

        return $output;
    }

    /**
     * 归一化单个输入：统一分辨率 / 帧率 / 像素格式，并保证存在音轨
     */
    private function normalizeInput(string $srcPath, float $duration): string
    {
        if (!is_file($srcPath)) {
            return $srcPath;
        }

        [$w, $h] = $this->resolution();
        $fps = (int) $this->config['fps'];

        $scale = sprintf(
            'scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=%d,format=yuv420p',
            $w,
            $h,
            $w,
            $h,
            $fps
        );

        $output = $this->getTempPath('norm.mp4');

        if ($this->hasAudio($srcPath)) {
            $command = sprintf(
                'ffmpeg -y -i %s -vf %s -c:v %s -preset %s -pix_fmt yuv420p -c:a %s -ar 44100 -ac 2 -shortest %s 2>/dev/null',
                escapeshellarg($srcPath),
                escapeshellarg($scale),
                escapeshellarg($this->config['video_codec']),
                escapeshellarg($this->config['preset']),
                escapeshellarg($this->config['audio_codec']),
                escapeshellarg($output)
            );
        } else {
            $command = sprintf(
                'ffmpeg -y -i %s -f lavfi -i anullsrc=channel_layout=stereo:sample_rate=44100 -vf %s -c:v %s -preset %s -pix_fmt yuv420p -c:a %s -shortest %s 2>/dev/null',
                escapeshellarg($srcPath),
                escapeshellarg($scale),
                escapeshellarg($this->config['video_codec']),
                escapeshellarg($this->config['preset']),
                escapeshellarg($this->config['audio_codec']),
                escapeshellarg($output)
            );
        }

        exec($command, $outputLines, $returnCode);

        return ($returnCode === 0 && is_file($output)) ? $output : $srcPath;
    }

    /**
     * 判断视频是否包含音轨（ffprobe）
     */
    private function hasAudio(string $path): bool
    {
        $command = sprintf(
            'ffprobe -v error -select_streams a -show_entries stream=index -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($path)
        );
        exec($command, $outputLines, $returnCode);

        if ($returnCode !== 0) {
            return false;
        }

        foreach ($outputLines as $line) {
            if (trim($line) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * TransitionType -> ffmpeg xfade 转场名
     *
     * @var array<string, string>
     */
    private const XFADE_MAP = [
        'fade' => 'fade',
        'dissolve' => 'fade',
        'blur' => 'fade',
        'radial_blur' => 'fade',
        'zoom_in' => 'fade',
        'zoom_out' => 'fade',
        'slide_left' => 'slideleft',
        'slide_right' => 'slideright',
        'slide_up' => 'slideup',
        'slide_down' => 'slidedown',
        'cross_wipe' => 'wiperight',
    ];

    private function mapXfade(TransitionType $type): string
    {
        return self::XFADE_MAP[$type->value];
    }

    /**
     * 添加开场 / 结尾视频（前后拼接，保留音轨）
     */
    private function addFrameVideos(string $inputPath, array $options = []): string
    {
        if (!is_file($inputPath)) {
            return $inputPath;
        }

        $inputs = [];
        $parts = [];
        $idx = 0;

        if ($this->frameManager->hasOpening()) {
            $opening = $this->frameManager->getOpening();
            $path = $this->normalizeInput($opening->getVideoUrl(), (float) $opening->duration);
            $inputs[] = '-i ' . escapeshellarg($path);
            $parts[] = "[{$idx}:v][{$idx}:a]";
            $idx++;
        }

        $inputs[] = '-i ' . escapeshellarg($inputPath);
        $parts[] = "[{$idx}:v][{$idx}:a]";
        $idx++;

        if ($this->frameManager->hasClosing()) {
            $closing = $this->frameManager->getClosing();
            $path = $this->normalizeInput($closing->getVideoUrl(), (float) $closing->duration);
            $inputs[] = '-i ' . escapeshellarg($path);
            $parts[] = "[{$idx}:v][{$idx}:a]";
        }

        $filterComplex = implode('', $parts) . sprintf('concat=n=%d:v=1:a=1[v][a]', $idx);

        $output = $this->generateOutputPath();
        $command = sprintf(
            'ffmpeg -y %s -filter_complex %s -map "[v]" -map "[a]" -c:v %s -preset %s -pix_fmt yuv420p -c:a %s %s 2>/dev/null',
            implode(' ', $inputs),
            escapeshellarg($filterComplex),
            escapeshellarg($this->config['video_codec']),
            escapeshellarg($this->config['preset']),
            escapeshellarg($this->config['audio_codec']),
            escapeshellarg($output)
        );

        exec($command, $outputLines, $returnCode);

        return ($returnCode === 0 && is_file($output)) ? $output : $inputPath;
    }

    /**
     * 添加背景音乐（与原音混音）
     */
    private function addBackgroundMusic(string $inputPath, array $options = []): string
    {
        if (!is_file($inputPath) || !is_file($this->backgroundMusic['path'])) {
            return $inputPath;
        }

        $output = $this->generateOutputPath();
        $volume = $this->backgroundMusic['volume'] ?? 0.3;

        $command = sprintf(
            'ffmpeg -y -i %s -i %s -filter_complex "[1:a]volume=%.2f[music];[0:a][music]amix=inputs=2:duration=longest[aout]" -map 0:v -map "[aout]" -c:v %s -preset %s -c:a %s %s 2>/dev/null',
            escapeshellarg($inputPath),
            escapeshellarg($this->backgroundMusic['path']),
            $volume,
            escapeshellarg($this->config['video_codec']),
            escapeshellarg($this->config['preset']),
            escapeshellarg($this->config['audio_codec']),
            escapeshellarg($output)
        );

        exec($command, $outputLines, $returnCode);

        return ($returnCode === 0 && is_file($output)) ? $output : $inputPath;
    }

    /**
     * 添加字幕（需配置 subtitle_font，否则跳过）
     */
    private function addSubtitle(string $inputPath, array $options = []): string
    {
        $font = $this->config['subtitle_font'];
        if ($font === null || !is_file($inputPath)) {
            if ($font === null) {
                $this->log('warning', '未配置 subtitle_font，跳过字幕绘制');
            }

            return $inputPath;
        }

        $output = $this->generateOutputPath();
        $fontSize = $this->subtitle['font_size'] ?? 36;
        $color = $this->subtitle['color'] ?? 'white';
        $text = str_replace(["\r", "\n"], [' ', ' '], (string) $this->subtitle['text']);

        $command = sprintf(
            "ffmpeg -y -i %s -vf \"drawtext=fontfile=%s:text=%s:fontsize=%d:fontcolor=%s:x=(w-text_w)/2:y=h-th-30\" -c:v %s -preset %s -c:a copy %s 2>/dev/null",
            escapeshellarg($inputPath),
            escapeshellarg($font),
            escapeshellarg($text),
            $fontSize,
            $color,
            escapeshellarg($this->config['video_codec']),
            escapeshellarg($this->config['preset']),
            escapeshellarg($output)
        );

        exec($command, $outputLines, $returnCode);

        return ($returnCode === 0 && is_file($output)) ? $output : $inputPath;
    }

    /**
     * 计算总时长（秒，去除转场重叠）
     */
    private function calculateTotalDuration(): float
    {
        $duration = 0.0;

        foreach ($this->sceneVideos as $scene) {
            $duration += $scene->duration;
        }

        // 减去相邻转场的重叠时长
        $sorted = $this->sceneVideos;
        usort($sorted, fn(SceneVideo $a, SceneVideo $b) => $a->order <=> $b->order);
        for ($i = 1; $i < count($sorted); $i++) {
            $effect = $this->transitionManager->getTransition($sorted[$i - 1]->sceneId, $sorted[$i]->sceneId);
            if ($effect !== null) {
                $duration -= min((float) $effect->duration, $sorted[$i - 1]->duration * 0.9, $sorted[$i]->duration * 0.9);
            }
        }

        if ($this->frameManager->hasOpening()) {
            $duration += $this->frameManager->getOpeningDuration();
        }
        if ($this->frameManager->hasClosing()) {
            $duration += $this->frameManager->getClosingDuration();
        }

        return max(0.0, $duration);
    }

    /**
     * 解析分辨率
     *
     * @return array{0: int, 1: int}
     */
    private function resolution(): array
    {
        $parts = explode('x', (string) $this->config['resolution']);
        $w = (int) $parts[0];
        if ($w <= 0) {
            $w = 1920;
        }
        $h = (int) ($parts[1] ?? 1080);
        if ($h <= 0) {
            $h = 1080;
        }

        return [$w, $h];
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
     * 清理临时归一化文件
     *
     * @param array<int, string> $files
     */
    private function cleanup(array $files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
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
    public function getFrameManager(): \Kode\AiAgent\Drama\FrameVideoManager
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
