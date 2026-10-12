<?php

declare(strict_types=1);

namespace Kode\AiAgent\Video;

use Kode\AiAgent\Support\FFmpegEscaper;

/**
 * 视频剪辑操作
 */
final class ClipOperationConfig
{
    public function __construct(
        public ClipOperation $operation,
        public array $params = [],
        public ?float $startTime = null,
        public ?float $endTime = null,
    ) {}

    /**
     * 获取 FFmpeg 滤镜字符串
     */
    public function toFFmpegFilter(): string
    {
        return match ($this->operation) {
            ClipOperation::CUT => $this->buildCutFilter(),
            ClipOperation::TRIM => $this->buildTrimFilter(),
            ClipOperation::SPLIT => $this->buildSplitFilter(),
            ClipOperation::SPEED => $this->buildSpeedFilter(),
            ClipOperation::REVERSE => 'reverse',
            ClipOperation::ROTATE => $this->buildRotateFilter(),
            ClipOperation::CROP => $this->buildCropFilter(),
            ClipOperation::SCALE => $this->buildScaleFilter(),
            ClipOperation::PAD => $this->buildPadFilter(),
            ClipOperation::DELAY => $this->buildDelayFilter(),
        };
    }

    private function buildCutFilter(): string
    {
        $start = max(0.0, (float) ($this->startTime ?? 0.0));
        $end = max($start, (float) ($this->endTime ?? $start));
        $duration = $end - $start;

        return "trim=start={$start}:duration={$duration},setpts=PTS-STARTPTS";
    }

    private function buildTrimFilter(): string
    {
        return $this->buildCutFilter();
    }

    private function buildSplitFilter(): string
    {
        $count = max(2, $this->intParam('count', 2));

        return "split={$count}";
    }

    private function buildSpeedFilter(): string
    {
        $speed = (float) ($this->params['speed'] ?? 1.0);
        if (!is_finite($speed) || $speed <= 0.0) {
            $speed = 1.0;
        }
        $pts = 1.0 / $speed;

        return "setpts={$pts}*PTS";
    }

    private function buildRotateFilter(): string
    {
        $angle = (float) ($this->params['angle'] ?? 0.0);

        if ($angle == 90.0) {
            return 'transpose=1';
        } elseif ($angle == 180.0) {
            return 'transpose=2,transpose=2';
        } elseif ($angle == 270.0) {
            return 'transpose=2';
        }

        return "frei0r=rotate:{$angle}*pi/180";
    }

    private function buildCropFilter(): string
    {
        $width = $this->exprParam('width', 'iw');
        $height = $this->exprParam('height', 'ih');
        $x = $this->exprParam('x', '0');
        $y = $this->exprParam('y', '0');

        return "crop={$width}:{$height}:{$x}:{$y}";
    }

    private function buildScaleFilter(): string
    {
        $width = $this->exprParam('width', '-1');
        $height = $this->exprParam('height', '-1');

        return "scale={$width}:{$height}";
    }

    private function buildPadFilter(): string
    {
        $width = $this->intParam('width', 1920);
        $height = $this->intParam('height', 1080);
        $x = $this->exprParam('x', '(ow-iw)/2');
        $y = $this->exprParam('y', '(oh-ih)/2');
        $color = FFmpegEscaper::color((string) ($this->params['color'] ?? 'black'));

        return "pad={$width}:{$height}:{$x}:{$y}:{$color}";
    }

    private function buildDelayFilter(): string
    {
        $delay = max(0, $this->intParam('delay', 0));

        return "adelay={$delay}|{$delay}";
    }

    /**
     * 取整型参数（非法值回落默认）
     */
    private function intParam(string $key, int $default): int
    {
        $value = $this->params[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * 取 ffmpeg 表达式参数
     *
     * 仅放行表达式安全字符（字母数字与 + - * / ( ) . 空格），
     * 拒绝 `,` `;` `:` 引号 反斜杠等滤镜图分隔符/注入字符。
     */
    private function exprParam(string $key, string $default): string
    {
        $value = (string) ($this->params[$key] ?? $default);
        if ($value === '' || preg_match('/^[a-zA-Z0-9_+\-*\/(). ]+$/', $value) !== 1) {
            return $default;
        }

        return $value;
    }

    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'params' => $this->params,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
        ];
    }
}
