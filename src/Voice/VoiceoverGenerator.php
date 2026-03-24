<?php

declare(strict_types=1);

namespace Kode\AiAgent\Voice;

/**
 * 语音角色枚举
 */
enum VoiceRole: string
{
    case NARRATOR = 'narrator';
    case MALE = 'male';
    case FEMALE = 'female';
    case CUSTOM = 'custom';
}

/**
 * 语音风格
 */
enum VoiceStyle: string
{
    case NATURAL = 'natural';
    case PROFESSIONAL = 'professional';
    case FRIENDLY = 'friendly';
    case EMOTIONAL = 'emotional';
    case CARTOON = 'cartoon';
    case DOCUMENTARY = 'documentary';
}

/**
 * 配音片段
 */
final class VoiceSegment
{
    public function __construct(
        public string $text,
        public ?VoiceRole $role = null,
        public ?string $voiceId = null,
        public VoiceStyle $style = VoiceStyle::NATURAL,
        public float $speed = 1.0,
        public float $pitch = 1.0,
        public float $volume = 1.0,
    ) {}

    /**
     * 获取语音生成选项
     */
    public function toOptions(): array
    {
        return [
            'text' => $this->text,
            'voice_id' => $this->voiceId,
            'role' => $this->role?->value,
            'style' => $this->style->value,
            'speed' => $this->speed,
            'pitch' => $this->pitch,
            'volume' => $this->volume,
        ];
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'role' => $this->role?->value,
            'voice_id' => $this->voiceId,
            'style' => $this->style->value,
            'speed' => $this->speed,
            'pitch' => $this->pitch,
            'volume' => $this->volume,
        ];
    }
}

/**
 * 配音器接口
 */
interface VoiceoverAdapterInterface
{
    /**
     * 文本转语音
     *
     * @param VoiceSegment $segment 配音片段
     * @param array $options 选项
     * @return string 生成的音频文件路径
     */
    public function textToSpeech(VoiceSegment $segment, array $options = []): string;

    /**
     * 批量文本转语音
     *
     * @param VoiceSegment[] $segments 配音片段数组
     * @param array $options 选项
     * @return string[] 生成的音频文件路径数组
     */
    public function textToSpeechBatch(array $segments, array $options = []): array;
}

/**
 * 配音/旁白生成器
 *
 * 支持将文本转换为语音，可用于视频旁白、解说等场景。
 *
 * @package Kode\AiAgent\Voice
 *
 * @example
 * ```php
 * $generator = new VoiceoverGenerator($adapter);
 *
 * // 单段配音
 * $audioPath = $generator->generate('欢迎观看今天的节目', [
 *     'role' => VoiceRole::NARRATOR,
 *     'style' => VoiceStyle::FRIENDLY,
 * ]);
 *
 * // 多段配音
 * $audioPaths = $generator->generateBatch([
 *     new VoiceSegment('第一段配音内容', VoiceRole::MALE),
 *     new VoiceSegment('第二段配音内容', VoiceRole::FEMALE),
 * ]);
 *
 * // 合并音频
 * $finalAudio = $generator->mergeAudio($audioPaths);
 * ```
 */
final class VoiceoverGenerator
{
    private ?VoiceoverAdapterInterface $adapter;

    public function __construct(?VoiceoverAdapterInterface $adapter = null)
    {
        $this->adapter = $adapter;
    }

    /**
     * 生成配音
     *
     * @param string $text 文本内容
     * @param array $options 生成选项
     * @return string 生成的音频文件路径
     */
    public function generate(string $text, array $options = []): string
    {
        $segment = new VoiceSegment(
            text: $text,
            role: $options['role'] ?? null,
            voiceId: $options['voice_id'] ?? null,
            style: $options['style'] ?? VoiceStyle::NATURAL,
            speed: $options['speed'] ?? 1.0,
            pitch: $options['pitch'] ?? 1.0,
            volume: $options['volume'] ?? 1.0,
        );

        if ($this->adapter !== null) {
            return $this->adapter->textToSpeech($segment, $options);
        }

        return $this->simulateGeneration($segment, $options);
    }

    /**
     * 批量生成配音
     *
     * @param VoiceSegment[] $segments 配音片段数组
     * @param array $options 生成选项
     * @return string[] 音频文件路径数组
     */
    public function generateBatch(array $segments, array $options = []): array
    {
        if ($this->adapter !== null) {
            return $this->adapter->textToSpeechBatch($segments, $options);
        }

        $audioPaths = [];
        foreach ($segments as $index => $segment) {
            $audioPaths[$index] = $this->simulateGeneration($segment, $options);
        }

        return $audioPaths;
    }

    /**
     * 从剧本生成配音
     *
     * @param string $script 剧本文本
     * @param array $options 生成选项
     * @return string[] 音频文件路径数组
     */
    public function generateFromScript(string $script, array $options = []): array
    {
        $sentences = $this->splitIntoSentences($script);
        $segments = [];

        $role = $options['role'] ?? VoiceRole::NARRATOR;
        $style = $options['style'] ?? VoiceStyle::NATURAL;

        foreach ($sentences as $index => $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) continue;

            $segments[] = new VoiceSegment(
                text: $sentence,
                role: $role,
                voiceId: $options['voice_id'] ?? null,
                style: $style,
                speed: $options['speed'] ?? 1.0,
                pitch: $options['pitch'] ?? 1.0,
                volume: $options['volume'] ?? 1.0,
            );
        }

        return $this->generateBatch($segments, $options);
    }

    /**
     * 合并多个音频文件
     *
     * @param string[] $audioPaths 音频文件路径数组
     * @param array $options 合并选项
     * @return string 合并后的音频文件路径
     */
    public function mergeAudio(array $audioPaths, array $options = []): string
    {
        $outputPath = $options['output'] ?? $this->generateOutputPath();

        $tempDir = dirname($outputPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        if (count($audioPaths) === 1) {
            return $audioPaths[0];
        }

        $concatFile = $tempDir . '/concat_' . bin2hex(random_bytes(4)) . '.txt';
        $content = [];

        foreach ($audioPaths as $path) {
            if (file_exists($path)) {
                $content[] = "file '" . addslashes($path) . "'";
            }
        }

        if (empty($content)) {
            throw new \RuntimeException('没有可合并的音频文件');
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
     * 为视频添加配音
     *
     * @param string $videoPath 视频文件路径
     * @param string $audioPath 配音文件路径
     * @param array $options 选项
     * @return string 输出视频路径
     */
    public function addToVideo(string $videoPath, string $audioPath, array $options = []): string
    {
        $outputPath = $options['output'] ?? str_replace('.mp4', '_with_voice.mp4', $videoPath);
        $audioVolume = $options['audio_volume'] ?? 1.0;
        $videoVolume = $options['video_volume'] ?? 0.0;
        $delay = $options['delay'] ?? 0;

        $command = sprintf(
            'ffmpeg -y -i %s -i %s -filter_complex "[1:a]adelay=%d[a];[0:a]volume=%.2f[v0];[a]volume=%.2f[a];[v0][a]amix=inputs=2:duration=longest[aout]" -map "[aout]" %s 2>/dev/null',
            escapeshellarg($videoPath),
            escapeshellarg($audioPath),
            $delay * 1000,
            $videoVolume,
            $audioVolume,
            escapeshellarg($outputPath)
        );

        exec($command, $outputLines, $returnCode);

        return $outputPath;
    }

    /**
     * 获取可用语音角色列表
     */
    public function getAvailableRoles(): array
    {
        return array_map(fn($role) => $role->value, VoiceRole::cases());
    }

    /**
     * 获取可用语音风格列表
     */
    public function getAvailableStyles(): array
    {
        return array_map(fn($style) => $style->value, VoiceStyle::cases());
    }

    /**
     * 分割文本为句子
     */
    private function splitIntoSentences(string $text): array
    {
        $sentences = preg_split('/[。！？\n]+/', $text);
        return array_filter($sentences, fn($s) => trim($s) !== '');
    }

    /**
     * 生成输出路径
     */
    private function generateOutputPath(): string
    {
        $outputDir = 'var/drama/audio';

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        return sprintf(
            '%s/voice_%s.mp3',
            $outputDir,
            date('Ymd-His') . '-' . bin2hex(random_bytes(4))
        );
    }

    /**
     * 模拟生成（无实际适配器时）
     */
    private function simulateGeneration(VoiceSegment $segment, array $options): string
    {
        $outputPath = $this->generateOutputPath();

        $duration = strlen($segment->text) / 10 * $segment->speed;
        $sampleRate = 44100;
        $numSamples = (int) ($sampleRate * $duration);

        $command = sprintf(
            'ffmpeg -y -f lavfi -i "sine=frequency=440:duration=%.2f" -af "volume=%.2f" %s 2>/dev/null',
            $duration,
            $segment->volume * 0.5,
            escapeshellarg($outputPath)
        );

        exec($command);

        return $outputPath;
    }
}