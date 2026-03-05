<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support\Facade;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\Prompt;
use Kode\AiAgent\Exception\ConfigurationException;
use Kode\Facade\Facade;

/**
 * AI Agent 门面类
 * 
 * 继承 kode/facade 提供简洁的静态调用接口。
 * 
 * @package Kode\AiAgent\Support\Facade
 * 
 * @example
 * ```php
 * // 设置容器
 * Ai::setContainer($container);
 * 
 * // 快速发送消息
 * $response = Ai::chat('你好，世界');
 * 
 * // 流式响应
 * foreach (Ai::stream('讲个故事') as $chunk) {
 *     echo $chunk;
 * }
 * ```
 * 
 * @method static ResponseInterface chat(string $message, array $options = [])
 * @method static \Generator stream(string $message, array $options = [])
 * @method static AdapterInterface using(string $platform)
 * @method static void setDefaultAdapter(AdapterInterface $adapter)
 * @method static void register(string $name, AdapterInterface $adapter)
 * @method static AdapterInterface adapter()
 * @method static void reset()
 */
final class Ai extends Facade
{
    private static ?AdapterInterface $defaultAdapter = null;
    private static array $adapters = [];

    /**
     * 获取门面标识
     */
    protected static function id(): string
    {
        return 'ai';
    }

    /**
     * 获取实例
     */
    public static function getInstance(): object
    {
        return new self();
    }

    /**
     * 发送聊天消息
     */
    #[\NoDiscard]
    public function chat(string $message, array $options = []): ResponseInterface
    {
        return self::adapter()->send(new Prompt($message), $options);
    }

    /**
     * 流式响应
     */
    #[\NoDiscard]
    public function stream(string $message, array $options = []): \Generator
    {
        return self::adapter()->stream(new Prompt($message), $options);
    }

    /**
     * 指定使用的平台
     */
    public function using(string $platform): AdapterInterface
    {
        return self::resolveAdapter($platform);
    }

    /**
     * 设置默认适配器
     */
    public function setDefaultAdapter(AdapterInterface $adapter): void
    {
        self::$defaultAdapter = $adapter;
    }

    /**
     * 注册适配器
     */
    public function register(string $name, AdapterInterface $adapter): void
    {
        self::$adapters[$name] = $adapter;
    }

    /**
     * 获取默认适配器
     */
    public function adapter(): AdapterInterface
    {
        return self::$defaultAdapter ?? throw ConfigurationException::missing('default_adapter');
    }

    /**
     * 解析适配器
     */
    private static function resolveAdapter(string $name): AdapterInterface
    {
        return self::$adapters[$name] ?? throw ConfigurationException::unsupportedPlatform($name);
    }

    /**
     * 重置所有适配器
     */
    public function reset(): void
    {
        self::$defaultAdapter = null;
        self::$adapters = [];
    }
}
