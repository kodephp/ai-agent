<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Client;

use Kode\HttpClient\Factory;
use Kode\HttpClient\HttpClient;

/**
 * HTTP 客户端工厂
 * 
 * 封装 kode/http-client，为 AI Agent 提供统一的 HTTP 客户端。
 * 支持多种运行时环境（FPM、CLI、Swoole、Swow、Fiber）。
 * 
 * @package Kode\AiAgent\Infrastructure\Client
 * 
 * @example
 * ```php
 * $client = HttpClientFactory::create([
 *     'timeout' => 30,
 * ]);
 * 
 * $response = $client->sendRequest($request);
 * ```
 */
final class HttpClientFactory
{
    private const DEFAULT_TIMEOUT = 30;

    /**
     * 创建 HTTP 客户端
     *
     * @param array{
     *     timeout?: float,
     *     retries?: int,
     *     auth?: array,
     *     rate_limit?: array,
     *     cache?: bool,
     *     logger?: callable
     * } $config 配置选项
     * @return HttpClient HTTP 客户端实例
     */
    public static function create(array $config = []): HttpClient
    {
        return Factory::create([
            'timeout' => $config['timeout'] ?? self::DEFAULT_TIMEOUT,
            'retries' => $config['retries'] ?? 3,
            'auth' => $config['auth'] ?? null,
            'rate_limit' => $config['rate_limit'] ?? null,
            'cache' => $config['cache'] ?? false,
            'logger' => $config['logger'] ?? null,
        ]);
    }

    /**
     * 创建 OpenAI 专用客户端
     *
     * @param string $apiKey API Key
     * @param array $config 配置选项
     * @return HttpClient HTTP 客户端实例
     */
    public static function forOpenAI(string $apiKey, array $config = []): HttpClient
    {
        return self::create(array_merge($config, [
            'auth' => [
                'type' => 'bearer',
                'credential' => $apiKey,
            ],
        ]));
    }

    /**
     * 创建 Anthropic 专用客户端
     *
     * @param string $apiKey API Key
     * @param array $config 配置选项
     * @return HttpClient HTTP 客户端实例
     */
    public static function forAnthropic(string $apiKey, array $config = []): HttpClient
    {
        return self::create(array_merge($config, [
            'auth' => [
                'type' => 'api_key',
                'credential' => $apiKey,
                'header' => 'x-api-key',
            ],
        ]));
    }
}
