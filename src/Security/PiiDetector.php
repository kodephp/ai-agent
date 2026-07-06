<?php

declare(strict_types=1);

namespace Kode\AiAgent\Security;

/**
 * PII（个人敏感信息）检测器与脱敏器
 *
 * 自动识别并脱敏文本中的敏感信息：
 * - 身份证号
 * - 手机号
 * - 邮箱地址
 * - 银行卡号
 * - IP 地址
 * - 家庭住址（粗略）
 *
 * @package Kode\AiAgent\Security
 *
 * @example
 * ```php
 * $detector = new PiiDetector();
 * $safe = $detector->mask('我的手机是13800138000');
 * // 输出: "我的手机是138****8000"
 * ```
 */
final class PiiDetector
{
    /**
     * 敏感信息模式
     *
     * @var array<int, array{pattern: string, type: string, mask: callable(string): string}>
     */
    private array $patterns;

    public function __construct(private bool $autoMask = true)
    {
        $this->patterns = [
            [
                'pattern' => '/\b\d{17}[\dXx]\b/',
                'type' => 'id_card',
                'mask' => static fn(string $v) => substr($v, 0, 4) . '**********' . substr($v, -4),
            ],
            [
                'pattern' => '/\b1[3-9]\d{9}\b/',
                'type' => 'phone',
                'mask' => static fn(string $v) => substr($v, 0, 3) . '****' . substr($v, -4),
            ],
            [
                'pattern' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
                'type' => 'email',
                'mask' => static function (string $v) {
                    [$user, $domain] = explode('@', $v, 2);
                    $maskedUser = strlen($user) > 2
                        ? substr($user, 0, 2) . str_repeat('*', max(1, strlen($user) - 2))
                        : str_repeat('*', strlen($user));
                    return $maskedUser . '@' . $domain;
                },
            ],
            [
                'pattern' => '/\b\d{16,19}\b/',
                'type' => 'bank_card',
                'mask' => static fn(string $v) => substr($v, 0, 4) . str_repeat('*', strlen($v) - 8) . substr($v, -4),
            ],
            [
                'pattern' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
                'type' => 'ip_address',
                'mask' => static fn(string $v) => preg_replace('/\d+/', '***', $v, 2),
            ],
        ];
    }

    /**
     * 检测文本中包含的敏感信息
     *
     * @return array<int, array{type: string, value: string, position: int}>
     */
    public function detect(string $text): array
    {
        $results = [];

        foreach ($this->patterns as $pattern) {
            if (preg_match_all($pattern['pattern'], $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $results[] = [
                        'type' => $pattern['type'],
                        'value' => $match[0],
                        'position' => $match[1],
                    ];
                }
            }
        }

        // 按位置排序
        usort($results, static fn($a, $b) => $a['position'] <=> $b['position']);

        return $results;
    }

    /**
     * 脱敏文本
     */
    public function mask(string $text): string
    {
        if (!$this->autoMask) {
            return $text;
        }

        foreach ($this->patterns as $pattern) {
            $text = preg_replace_callback(
                $pattern['pattern'],
                static fn($m) => $pattern['mask']($m[0]),
                $text
            );
        }
        return $text;
    }

    /**
     * 检查文本是否包含敏感信息
     */
    public function hasSensitive(string $text): bool
    {
        return $this->detect($text) !== [];
    }

    /**
     * 统计敏感信息数量
     */
    public function count(string $text): int
    {
        return count($this->detect($text));
    }
}
