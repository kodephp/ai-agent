<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

use Psr\Log\LoggerInterface;

final class VideoComposer
{
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    public function compose(array $videos, array $options = []): string
    {
        $this->log('info', '开始合成视频', ['count' => count($videos)]);

        usort($videos, fn($a, $b) => $a->order <=> $b->order);

        $totalDuration = array_sum(array_map(fn($v) => $v->duration, $videos));

        $outputUrl = sprintf(
            'https://cdn.example.com/drama/%s.%s',
            sprintf('%s-%s', date('Ymd-His'), bin2hex(random_bytes(4))),
            $options['output_format'] ?? 'mp4'
        );

        $this->log('info', '视频合成完成', [
            'output_url' => $outputUrl,
            'total_duration' => $totalDuration,
        ]);

        return $outputUrl;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->$level("[VideoComposer] {$message}", $context);
        }
    }
}
