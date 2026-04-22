<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Domain\Contract\AdapterInterface;
use Kode\AiAgent\Exception\ConfigurationException;
use Kode\HttpClient\Factory;

/**
 * 适配器工厂
 * 
 * 创建平台适配器实例，支持注册自定义适配器。
 * 
 * @package Kode\AiAgent\Infrastructure\Adapter
 * 
 * @example
 * ```php
 * // 创建适配器
 * $adapter = AdapterFactory::create('openai', [
 *     'api_key' => 'sk-xxx',
 *     'model' => 'gpt-4o',
 * ]);
 * 
 * // 注册自定义适配器
 * AdapterFactory::register('my_platform', MyAdapter::class);
 * ```
 */
final class AdapterFactory
{
    private static array $registry = [
        'openai' => OpenAiAdapter::class,
        'anthropic' => AnthropicAdapter::class,
        'claude' => AnthropicAdapter::class,
        'deepseek' => DeepSeekAdapter::class,
        'aliyun' => AliyunAdapter::class,
        'qwen' => AliyunAdapter::class,
        'tongyi' => AliyunAdapter::class,
        'gemini' => GeminiAdapter::class,
        'google' => GeminiAdapter::class,
        'baidu' => BaiduAdapter::class,
        'wenxin' => BaiduAdapter::class,
        'ernie' => BaiduAdapter::class,
        'tencent' => TencentAdapter::class,
        'hunyuan' => TencentAdapter::class,
        'xunfei' => XunfeiAdapter::class,
        'spark' => XunfeiAdapter::class,
        'xinghuo' => XunfeiAdapter::class,
        'seedance' => SeedanceAdapter::class,
        'seedance2' => SeedanceAdapter::class,
        'bytedance' => SeedanceAdapter::class,
    ];

    /**
     * 创建适配器实例
     */
    public static function create(string $platform, array $config): AdapterInterface
    {
        $platform = strtolower($platform);

        if (!isset(self::$registry[$platform])) {
            throw ConfigurationException::unsupportedPlatform($platform);
        }

        $adapterClass = self::$registry[$platform];

        $client = Factory::create([
            'timeout' => $config['timeout'] ?? 30,
            'retries' => $config['retries'] ?? 3,
        ]);

        return new $adapterClass($client, $config);
    }

    /**
     * 快速创建 OpenAI 适配器
     */
    public static function openai(string $apiKey, array $config = []): AdapterInterface
    {
        return self::create('openai', array_merge($config, ['api_key' => $apiKey]));
    }

    /**
     * 快速创建 Anthropic 适配器
     */
    public static function anthropic(string $apiKey, array $config = []): AdapterInterface
    {
        return self::create('anthropic', array_merge($config, ['api_key' => $apiKey]));
    }

    /**
     * 快速创建 DeepSeek 适配器
     */
    public static function deepseek(string $apiKey, array $config = []): AdapterInterface
    {
        return self::create('deepseek', array_merge($config, ['api_key' => $apiKey]));
    }

    /**
     * 快速创建阿里云适配器
     */
    public static function aliyun(string $apiKey, array $config = []): AdapterInterface
    {
        return self::create('aliyun', array_merge($config, ['api_key' => $apiKey]));
    }

    /**
     * 快速创建 Gemini 适配器
     */
    public static function gemini(string $apiKey, array $config = []): AdapterInterface
    {
        return self::create('gemini', array_merge($config, ['api_key' => $apiKey]));
    }

    /**
     * 快速创建百度文心一言适配器
     */
    public static function baidu(string $apiKey, string $secretKey, array $config = []): AdapterInterface
    {
        return self::create('baidu', array_merge($config, [
            'api_key' => $apiKey,
            'secret_key' => $secretKey,
        ]));
    }

    /**
     * 快速创建腾讯混元适配器
     */
    public static function tencent(string $secretId, string $secretKey, array $config = []): AdapterInterface
    {
        return self::create('tencent', array_merge($config, [
            'secret_id' => $secretId,
            'secret_key' => $secretKey,
        ]));
    }

    /**
     * 快速创建讯飞星火适配器
     */
    public static function xunfei(string $appId, string $apiKey, string $apiSecret, array $config = []): AdapterInterface
    {
        return self::create('xunfei', array_merge($config, [
            'app_id' => $appId,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ]));
    }

    /**
     * 快速创建 Seedance 2.0 视频适配器
     */
    public static function seedance(string $apiKey, array $config = []): AdapterInterface
    {
        return self::create('seedance', array_merge($config, ['api_key' => $apiKey]));
    }

    /**
     * 注册自定义适配器
     */
    public static function register(string $name, string $adapterClass): void
    {
        if (!class_exists($adapterClass)) {
            throw new \InvalidArgumentException("适配器类不存在: {$adapterClass}");
        }

        if (!is_a($adapterClass, AdapterInterface::class, true)) {
            throw new \InvalidArgumentException("适配器类必须实现 AdapterInterface: {$adapterClass}");
        }

        self::$registry[strtolower($name)] = $adapterClass;
    }

    /**
     * 获取支持的适配器列表
     */
    public static function supported(): array
    {
        return array_unique(array_keys(self::$registry));
    }

    /**
     * 检查是否支持指定平台
     */
    public static function supports(string $platform): bool
    {
        return isset(self::$registry[strtolower($platform)]);
    }
}
