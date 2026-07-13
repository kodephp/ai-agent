<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama\Director;

use Kode\AiAgent\Drama\TransitionType;
use Kode\AiAgent\Log\LogManager;

/**
 * 剧本拆分器
 *
 * 将剧本（纯文本或结构化数组）拆分为若干分镜（DramaSegment）：
 * - 文本：按空行分块；支持行内指令（@title / @model / @provider /
 *   @bg / @bgv / @transition / @duration）做片段级控制
 * - 数组：直接按结构化片段映射（支持后续替换更优模型）
 *
 * @package Kode\AiAgent\Drama\Director
 *
 * @example
 * ```php
 * $splitter = new ScriptSplitter();
 * $segments = $splitter->split("场景1：清晨的街道\n@model seedance-2.5-pro\n\n场景2：两人相遇");
 * ```
 */
final class ScriptSplitter
{
    /**
     * @param string|array<int, array<string, mixed>|string> $script 剧本（文本或结构化数组）
     * @param array<string, mixed> $options default_transition / default_duration / style
     * @return array<int, DramaSegment>
     */
    public static function split(string|array $script, array $options = []): array
    {
        $defaultTransition = self::parseTransition(
            $options['default_transition'] ?? 'fade'
        );
        $defaultDuration = (int) ($options['default_duration'] ?? 5);
        $style = $options['style'] ?? null;

        if (is_array($script)) {
            return self::fromStructured($script, $defaultTransition, $defaultDuration, $style);
        }

        return self::fromText($script, $defaultTransition, $defaultDuration, $style);
    }

    /**
     * 结构化输入：每项可为字符串（作为提示词）或关联数组
     *
     * @param array<int, mixed> $items
     * @return array<int, DramaSegment>
     */
    private static function fromStructured(array $items, TransitionType $defaultTransition, int $defaultDuration, ?string $style): array
    {
        $segments = [];
        $order = 0;
        $lastModel = null;

        foreach ($items as $item) {
            $order++;
            $data = is_array($item) ? $item : ['prompt' => (string) $item];
            $data['order'] = $data['order'] ?? $order;
            $data['transition'] = $data['transition'] ?? 'fade';
            $data['duration'] = $data['duration'] ?? $defaultDuration;
            $data['style'] = $data['style'] ?? $style;

            if (!isset($data['model']) && $lastModel !== null) {
                $data['model'] = $lastModel;
            }

            $segment = DramaSegment::fromArray($data);
            if ($segment->model !== null) {
                $lastModel = $segment->model;
            }

            $segments[] = $segment;
        }

        return self::normalizeIds($segments);
    }

    /**
     * 文本输入：按空行分块，解析行内指令
     *
     * @return array<int, DramaSegment>
     */
    private static function fromText(string $script, TransitionType $defaultTransition, int $defaultDuration, ?string $style): array
    {
        $blocks = preg_split('/\n\s*\n/', $script);
        $blocks = array_values(array_filter($blocks, static fn($b) => trim($b) !== ''));

        $segments = [];
        $lastModel = null;

        foreach ($blocks as $index => $block) {
            $order = $index + 1;
            $directives = [];
            $lines = [];

            foreach (explode("\n", $block) as $line) {
                $line = rtrim($line);
                if (preg_match('/^@(\w+)\s+(.*)$/u', $line, $m)) {
                    $directives[strtolower($m[1])] = trim($m[2]);
                    continue;
                }
                $lines[] = $line;
            }

            $text = implode("\n", $lines);
            $title = $directives['title'] ?? self::extractTitle($text, $order);
            $prompt = $directives['prompt'] ?? $text;

            $transition = isset($directives['transition'])
                ? self::parseTransition($directives['transition'])
                : $defaultTransition;

            $duration = isset($directives['duration'])
                ? (int) $directives['duration']
                : $defaultDuration;

            $model = null;
            if (isset($directives['model']) || isset($directives['provider'])) {
                $model = new ModelBinding(
                    provider: $directives['provider'] ?? null,
                    model: $directives['model'] ?? null,
                );
            } elseif ($lastModel !== null) {
                $model = $lastModel;
            }

            $segments[] = new DramaSegment(
                id: "seg-{$order}",
                order: $order,
                title: $title,
                prompt: $prompt,
                transition: $transition,
                backgroundImage: $directives['bg'] ?? null,
                backgroundVideo: $directives['bgv'] ?? null,
                model: $model,
                duration: $duration,
                style: $style,
                status: 'pending',
            );

            if ($model !== null) {
                $lastModel = $model;
            }
        }

        return self::normalizeIds($segments);
    }

    /**
     * 提取标题：去掉 "场景N：" / "N." 等前缀
     */
    private static function extractTitle(string $text, int $order): string
    {
        $firstLine = strtok($text, "\n") ?: $text;
        $firstLine = trim($firstLine);

        if (preg_match('/^(?:场景|scene)?\s*(\d+)\s*[.、：:]\s*(.*)$/iu', $firstLine, $m)) {
            $clean = trim($m[2]);
            return $clean !== '' ? $clean : "片段{$order}";
        }

        return $firstLine !== '' ? $firstLine : "片段{$order}";
    }

    /**
     * 解析转场类型
     */
    private static function parseTransition(string $type): TransitionType
    {
        return TransitionType::tryFrom(strtolower(trim($type))) ?? TransitionType::FADE;
    }

    /**
     * 规整 ID，确保 seg-<order> 连续
     *
     * @param array<int, DramaSegment> $segments
     * @return array<int, DramaSegment>
     */
    private static function normalizeIds(array $segments): array
    {
        return array_map(
            static fn(DramaSegment $s, int $i) => $s->with([
                'id' => 'seg-' . ($i + 1),
                'order' => $i + 1,
            ]),
            $segments,
            array_keys($segments)
        );
    }
}
