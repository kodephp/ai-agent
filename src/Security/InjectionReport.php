<?php

declare(strict_types=1);

namespace Kode\AiAgent\Security;

/**
 * 注入检测报告
 *
 * @package Kode\AiAgent\Security
 */
final readonly class InjectionReport
{
    /**
     * @param array<int, array{category: string, severity: int, description: string, pattern: string}> $matches
     */
    public function __construct(
        public string $input,
        public bool $malicious,
        public array $matches,
        public int $maxSeverity,
        public int $totalSeverity,
    ) {}

    /**
     * 是否为恶意输入
     */
    public function isMalicious(): bool
    {
        return $this->malicious;
    }

    public function toArray(): array
    {
        return [
            'is_malicious' => $this->malicious,
            'max_severity' => $this->maxSeverity,
            'total_severity' => $this->totalSeverity,
            'match_count' => count($this->matches),
            'matches' => $this->matches,
        ];
    }
}
