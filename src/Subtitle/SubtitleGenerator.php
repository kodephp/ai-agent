<?php

declare(strict_types=1);

namespace Kode\AiAgent\Subtitle;

/**
 * 字幕格式枚举
 */
enum SubtitleFormat: string
{
    case SRT = 'srt';
    case VTT = 'vtt';
    case ASS = 'ass';
    case JSON = 'json';
}

/**
 * 字幕条目
 */
final class SubtitleCue
{
    public function __construct(
        public int $index,
        public float $startTime,
        public float $endTime,
        public string $text,
        public array $metadata = [],
    ) {}

    /**
     * 获取格式化的时间字符串 (SRT 格式)
     */
    public function getFormattedTime(): string
    {
        return sprintf(
            '%s --> %s',
            self::formatSeconds($this->startTime),
            self::formatSeconds($this->endTime)
        );
    }

    /**
     * 格式化秒数为时间字符串
     */
    public static function formatSeconds(float $seconds): string
    {
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = (int) floor($seconds % 60);
        $millis = (int) (($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }

    /**
     * 获取时长（秒）
     */
    public function getDuration(): float
    {
        return $this->endTime - $this->startTime;
    }

    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'text' => $this->text,
            'duration' => $this->getDuration(),
            'metadata' => $this->metadata,
        ];
    }
}

/**
 * AI 字幕生成器
 *
 * 支持从音频/视频自动生成字幕，兼容多种格式。
 *
 * @package Kode\AiAgent\Subtitle
 *
 * @example
 * ```php
 * $generator = new SubtitleGenerator($adapter);
 *
 * // 从视频生成字幕
 * $subtitles = $generator->generateFromVideo('/path/to/video.mp4', [
 *     'language' => 'zh-CN',
 *     'format' => SubtitleFormat::SRT,
 * ]);
 *
 * // 保存字幕文件
 * $generator->save($subtitles, '/path/to/subtitles.srt');
 * ```
 */
final class SubtitleGenerator
{
    public function __construct(
        private ?object $adapter = null,
    ) {}

    /**
     * 从视频生成字幕
     *
     * @param string $videoPath 视频文件路径或 URL
     * @param array $options 生成选项
     * @return SubtitleCue[]
     */
    public function generateFromVideo(string $videoPath, array $options = []): array
    {
        $language = $options['language'] ?? 'zh-CN';
        $format = $options['format'] ?? SubtitleFormat::SRT;

        if ($this->adapter !== null && method_exists($this->adapter, 'transcribe')) {
            $transcription = $this->adapter->transcribe($videoPath, [
                'language' => $language,
            ]);

            return $this->parseTranscription($transcription);
        }

        return $this->simulateGeneration($videoPath, $options);
    }

    /**
     * 从音频生成字幕
     */
    public function generateFromAudio(string $audioPath, array $options = []): array
    {
        return $this->generateFromVideo($audioPath, $options);
    }

    /**
     * 批量生成字幕
     *
     * @param array $videos 视频路径数组
     * @param array $options 生成选项
     * @return array<string, SubtitleCue[]>
     */
    public function generateBatch(array $videos, array $options = []): array
    {
        $results = [];

        foreach ($videos as $index => $video) {
            $results[$index] = $this->generateFromVideo($video, $options);
        }

        return $results;
    }

    /**
     * 保存字幕文件
     *
     * @param SubtitleCue[] $subtitles 字幕条目数组
     * @param string $outputPath 输出文件路径
     * @param SubtitleFormat $format 字幕格式
     */
    public function save(array $subtitles, string $outputPath, SubtitleFormat $format = SubtitleFormat::SRT): void
    {
        $content = $this->formatSubtitles($subtitles, $format);

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, $content);
    }

    /**
     * 加载字幕文件
     *
     * @return SubtitleCue[]
     */
    public function load(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $format = match ($extension) {
            'srt' => SubtitleFormat::SRT,
            'vtt' => SubtitleFormat::VTT,
            'ass', 'ssa' => SubtitleFormat::ASS,
            'json' => SubtitleFormat::JSON,
            default => SubtitleFormat::SRT,
        };

        return $this->parseSubtitleContent($content, $format);
    }

    /**
     * 格式化字幕
     *
     * @param SubtitleCue[] $subtitles
     */
    public function formatSubtitles(array $subtitles, SubtitleFormat $format = SubtitleFormat::SRT): string
    {
        return match ($format) {
            SubtitleFormat::SRT => $this->formatSRT($subtitles),
            SubtitleFormat::VTT => $this->formatVTT($subtitles),
            SubtitleFormat::ASS => $this->formatASS($subtitles),
            SubtitleFormat::JSON => $this->formatJSON($subtitles),
        };
    }

    /**
     * 转换为 SRT 格式
     */
    private function formatSRT(array $subtitles): string
    {
        $output = [];

        foreach ($subtitles as $subtitle) {
            $output[] = (string) $subtitle->index;
            $output[] = $subtitle->getFormattedTime();
            $output[] = $subtitle->text;
            $output[] = '';
        }

        return implode("\n", $output);
    }

    /**
     * 转换为 VTT 格式
     */
    private function formatVTT(array $subtitles): string
    {
        $output = ["WEBVTT\n"];

        foreach ($subtitles as $subtitle) {
            $output[] = (string) $subtitle->index;
            $output[] = str_replace(',', '.', $subtitle->getFormattedTime());
            $output[] = $subtitle->text;
            $output[] = '';
        }

        return implode("\n", $output);
    }

    /**
     * 转换为 ASS 格式
     */
    private function formatASS(array $subtitles): string
    {
        $header = "[Script Info]
Title: Generated Subtitles
ScriptType: v4.00+
Collisions: Normal
PlayDepth: 0

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Default,Arial,20,&H00FFFFFF,&H000000FF,&H00000000,&H00000000,0,0,0,0,100,100,0,0,1,2,2,2,10,10,10,1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
";

        $output = [$header];

        foreach ($subtitles as $subtitle) {
            $start = $this->formatASS_Seconds($subtitle->startTime);
            $end = $this->formatASS_Seconds($subtitle->endTime);
            $text = str_replace("\n", "\\N", $subtitle->text);

            $output[] = sprintf(
                "Dialogue: 0,%s,%s,Default,,0,0,0,,%s",
                $start,
                $end,
                $text
            );
        }

        return implode("\n", $output);
    }

    /**
     * 转换为 JSON 格式
     */
    private function formatJSON(array $subtitles): string
    {
        $data = array_map(fn($s) => $s->toArray(), $subtitles);

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 格式化 ASS 时间
     */
    private function formatASS_Seconds(float $seconds): string
    {
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = (int) floor($seconds % 60);
        $centisecs = (int) (($seconds - floor($seconds)) * 100);

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $secs, $centisecs);
    }

    /**
     * 解析转录结果
     */
    private function parseTranscription(array $transcription): array
    {
        $cues = [];

        if (isset($transcription['segments'])) {
            foreach ($transcription['segments'] as $index => $segment) {
                $cues[] = new SubtitleCue(
                    index: $index + 1,
                    startTime: $segment['start'] ?? 0,
                    endTime: $segment['end'] ?? 0,
                    text: $segment['text'] ?? '',
                    metadata: $segment['metadata'] ?? [],
                );
            }
        } elseif (isset($transcription['text'])) {
            $cues[] = new SubtitleCue(
                index: 1,
                startTime: 0,
                endTime: $transcription['duration'] ?? 10,
                text: $transcription['text'],
            );
        }

        return $cues;
    }

    /**
     * 模拟生成（无实际 AI 适配器时）
     */
    private function simulateGeneration(string $videoPath, array $options): array
    {
        $cues = [];
        $duration = $options['duration'] ?? 60;
        $interval = $options['interval'] ?? 3;

        $texts = [
            '场景一：阳光明媚的早晨',
            '主人公小明走在公园的小路上',
            '突然，一只小鸟飞到了他的面前',
            '小明惊讶地停下了脚步',
            '小鸟唱起了美妙的歌声',
            '小明微笑着继续前行',
        ];

        for ($i = 0; $i < count($texts); $i++) {
            $startTime = $i * $interval;
            $endTime = min(($i + 1) * $interval, $duration);

            $cues[] = new SubtitleCue(
                index: $i + 1,
                startTime: $startTime,
                endTime: $endTime,
                text: $texts[$i],
            );
        }

        return $cues;
    }

    /**
     * 解析字幕内容
     *
     * @return SubtitleCue[]
     */
    private function parseSubtitleContent(string $content, SubtitleFormat $format): array
    {
        return match ($format) {
            SubtitleFormat::SRT => $this->parseSRT($content),
            SubtitleFormat::VTT => $this->parseVTT($content),
            SubtitleFormat::ASS => $this->parseASS($content),
            SubtitleFormat::JSON => $this->parseJSON($content),
        };
    }

    private function parseSRT(string $content): array
    {
        $cues = [];
        $blocks = preg_split('/\n\s*\n/', trim($content));
        $index = 1;

        foreach ($blocks as $block) {
            $lines = explode("\n", trim($block));
            if (count($lines) < 2) continue;

            $timeLine = $lines[1];
            if (preg_match('/(\d{2}:\d{2}:\d{2},\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2},\d{3})/', $timeLine, $matches)) {
                $cues[] = new SubtitleCue(
                    index: $index++,
                    startTime: $this->parseTimeToSeconds($matches[1]),
                    endTime: $this->parseTimeToSeconds($matches[2]),
                    text: implode("\n", array_slice($lines, 2)),
                );
            }
        }

        return $cues;
    }

    private function parseVTT(string $content): array
    {
        $content = preg_replace('/^WEBVTT\s*\n/', '', trim($content));
        return $this->parseSRT($content);
    }

    private function parseASS(string $content): array
    {
        $cues = [];
        $index = 1;

        if (preg_match_all('/Dialogue:\s*\d+,(\d+:\d+:\d+\.\d+),(\d+:\d+:\d+\.\d+),.*?,.*?,.*?,.*?,.*?,(.*)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $cues[] = new SubtitleCue(
                    index: $index++,
                    startTime: $this->parseASSTimeToSeconds($match[1]),
                    endTime: $this->parseASSTimeToSeconds($match[2]),
                    text: str_replace('\\N', "\n", $match[3]),
                );
            }
        }

        return $cues;
    }

    private function parseJSON(string $content): array
    {
        $data = json_decode($content, true);
        $cues = [];
        $index = 1;

        foreach ($data as $item) {
            $cues[] = new SubtitleCue(
                index: $index++,
                startTime: $item['start_time'] ?? 0,
                endTime: $item['end_time'] ?? 0,
                text: $item['text'] ?? '',
                metadata: $item['metadata'] ?? [],
            );
        }

        return $cues;
    }

    private function parseTimeToSeconds(string $time): float
    {
        preg_match('/(\d{2}):(\d{2}):(\d{2}),(\d{3})/', $time, $matches);
        return (float) ($matches[1] ?? 0) * 3600
            + (float) ($matches[2] ?? 0) * 60
            + (float) ($matches[3] ?? 0)
            + (float) ($matches[4] ?? 0) / 1000;
    }

    private function parseASSTimeToSeconds(string $time): float
    {
        preg_match('/(\d+):(\d{2}):(\d{2})\.(\d{2})/', $time, $matches);
        return (float) ($matches[1] ?? 0) * 3600
            + (float) ($matches[2] ?? 0) * 60
            + (float) ($matches[3] ?? 0)
            + (float) ($matches[4] ?? 0) / 100;
    }
}