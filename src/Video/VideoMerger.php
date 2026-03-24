<?php

declare(strict_types=1);

namespace Kode\AiAgent\Video;

use Kode\AiAgent\Log\LogManager;

final class VideoMerger
{
    private array $segments = [];
    private array $transitions = [];
    private array $audioTracks = [];
    private ?string $outputPath = null;

    public function addSegment(string $videoPath, array $options = []): self
    {
        $this->segments[] = [
            'path' => $videoPath,
            'start' => $options['start'] ?? 0,
            'duration' => $options['duration'] ?? null,
            'speed' => $options['speed'] ?? 1.0,
            'volume' => $options['volume'] ?? 1.0,
            'fade_in' => $options['fade_in'] ?? 0,
            'fade_out' => $options['fade_out'] ?? 0,
            'order' => count($this->segments),
        ];

        return $this;
    }

    public function addTransition(string $type, float $duration = 1.0, array $options = []): self
    {
        $validTypes = ['fade', 'dissolve', 'slide_left', 'slide_right', 'slide_up', 'slide_down', 'zoom', 'blur'];

        if (!in_array($type, $validTypes, true)) {
            throw new \InvalidArgumentException("Invalid transition type: {$type}");
        }

        $this->transitions[] = [
            'type' => $type,
            'duration' => $duration,
            'easing' => $options['easing'] ?? 'ease-in-out',
        ];

        return $this;
    }

    public function addAudioTrack(string $audioPath, array $options = []): self
    {
        $this->audioTracks[] = [
            'path' => $audioPath,
            'start' => $options['start'] ?? 0,
            'volume' => $options['volume'] ?? 1.0,
            'fade_in' => $options['fade_in'] ?? 0,
            'fade_out' => $options['fade_out'] ?? 0,
            'loop' => $options['loop'] ?? false,
        ];

        return $this;
    }

    public function setOutputPath(string $path): self
    {
        $this->outputPath = $path;
        return $this;
    }

    public function merge(): string
    {
        if (empty($this->segments)) {
            throw new \RuntimeException('No video segments to merge');
        }

        $outputPath = $this->outputPath ?? $this->generateOutputPath();

        LogManager::info('Merging video segments', [
            'segments' => count($this->segments),
            'transitions' => count($this->transitions),
            'audio_tracks' => count($this->audioTracks),
            'output' => $outputPath,
        ]);

        $this->generateMergeScript();

        return $outputPath;
    }

    public function getTotalDuration(): float
    {
        $total = 0.0;

        foreach ($this->segments as $segment) {
            $duration = $segment['duration'] ?? 10.0;
            $total += $duration / $segment['speed'];
        }

        foreach ($this->transitions as $transition) {
            $total -= $transition['duration'];
        }

        return max(0, $total);
    }

    public function toArray(): array
    {
        return [
            'segments' => $this->segments,
            'transitions' => $this->transitions,
            'audio_tracks' => $this->audioTracks,
            'output_path' => $this->outputPath,
            'total_duration' => $this->getTotalDuration(),
        ];
    }

    private function generateOutputPath(): string
    {
        return sprintf(
            'var/drama/output/%s.mp4',
            date('Ymd-His') . '-' . bin2hex(random_bytes(4))
        );
    }

    private function generateMergeScript(): string
    {
        $segments = $this->segments;
        $transitions = $this->transitions;

        $ffmpegCommands = [];

        $tempFiles = [];

        foreach ($segments as $index => $segment) {
            $tempFile = "var/drama/temp/segment_{$index}_" . bin2hex(random_bytes(4)) . '.mp4';
            $tempFiles[] = $tempFile;

            $filters = [];

            if ($segment['speed'] !== 1.0) {
                $filters[] = sprintf('setpts=%.2f*PTS', 1 / $segment['speed']);
            }

            if ($segment['fade_in'] > 0) {
                $filters[] = sprintf('fade=t:in:st=0:d=%.2f', $segment['fade_in']);
            }

            if ($segment['fade_out'] > 0) {
                $filters[] = sprintf('fade=t=out:st=%.2f:d=%.2f', ($segment['duration'] ?? 10) - $segment['fade_out'], $segment['fade_out']);
            }

            $filterComplex = empty($filters) ? '' : '-vf "' . implode(',', $filters) . '"';

            $ffmpegCommands[] = sprintf(
                'ffmpeg -y -i %s %s %s 2>/dev/null',
                escapeshellarg($segment['path']),
                $filterComplex,
                escapeshellarg($tempFile)
            );
        }

        $concatFile = 'var/drama/temp/concat_' . bin2hex(random_bytes(4)) . '.txt';
        $concatContent = implode("\n", array_map(fn($f) => "file '{$f}'", $tempFiles));

        $outputPath = $this->outputPath ?? $this->generateOutputPath();

        $finalCommand = sprintf(
            'ffmpeg -y -f concat -safe 0 -i %s -c copy %s 2>/dev/null',
            escapeshellarg($concatFile),
            escapeshellarg($outputPath)
        );

        LogManager::debug('Generated merge commands', [
            'segment_commands' => $ffmpegCommands,
            'concat_file' => $concatFile,
            'final_command' => $finalCommand,
        ]);

        return $outputPath;
    }
}