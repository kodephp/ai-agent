<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Exception\ConfigurationException;

/**
 * 流式响应处理 Trait
 * 
 * 提供适配器共用的流式响应处理方法。
 * 
 * @package Kode\AiAgent\Infrastructure\Adapter
 */
trait StreamHelper
{
    /**
     * 从流中读取一行
     */
    protected function readLine($stream): string
    {
        $line = '';
        while (!$stream->eof()) {
            $char = $stream->read(1);
            if ($char === "\n") {
                break;
            }
            $line .= $char;
        }
        return trim($line);
    }

    /**
     * 解析 SSE 数据行
     */
    protected function parseSseLine(string $line): ?array
    {
        if ($line === '' || !str_starts_with($line, 'data:')) {
            return null;
        }

        $jsonStr = trim(substr($line, 5));
        if ($jsonStr === '' || $jsonStr === '[DONE]') {
            return null;
        }

        $data = json_decode($jsonStr, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    /**
     * 检查是否为流结束标记
     */
    protected function isStreamDone(string $line): bool
    {
        return $line === 'data: [DONE]' || $line === '[DONE]';
    }

    /**
     * 强制使用 HTTPS
     */
    protected function ensureHttps(string $url): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw ConfigurationException::invalid('base_url', '必须使用 HTTPS 协议');
        }
        return $url;
    }

    /**
     * 验证 API Key 格式
     */
    protected function validateApiKeyFormat(string $apiKey, string $prefix = ''): bool
    {
        if (strlen($apiKey) < 16) {
            return false;
        }

        if ($prefix !== '' && !str_starts_with($apiKey, $prefix)) {
            return false;
        }

        return true;
    }
}
