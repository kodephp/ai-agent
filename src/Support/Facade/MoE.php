<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support\Facade;

use Kode\AiAgent\Domain\Contract\ResponseInterface;
use Kode\AiAgent\Moe\MoEGateway;
use Kode\AiAgent\Support\Builder\MoEBuilder;
use Kode\Context\Context as KodeContext;
use Kode\Facade\Facade;

/**
 * MOE 网关门面
 *
 * 提供简洁的静态调用接口访问 MoEGateway。
 *
 * @package Kode\AiAgent\Support\Facade
 *
 * @example
 * ```php
 * // 配置专家
 * MoE::addExpert('openai', env('OPENAI_API_KEY'), ['chat', 'vision'], priority: 10);
 * MoE::addExpert('deepseek', env('DEEPSEEK_API_KEY'), ['chat', 'code'], priority: 20);
 *
 * // 聊天（自动路由）
 * $response = MoE::chat('你好');
 *
 * // 指定能力路由
 * $response = MoE::chat('分析这段代码', ['capability' => 'code']);
 *
 * // 查看报告
 * $report = MoE::report();
 * ```
 *
 * @method static ResponseInterface chat(string $message, array $options = [])
 * @method static \Generator stream(string $message, array $options = [])
 * @method static MoEGateway gateway()
 * @method static self addExpert(string $platform, string|array $apiKey, array $capabilities = ['chat'], ?string $model = null, int $priority = 100, float $weight = 1.0, array $options = [])
 * @method static array report()
 * @method static void reset()
 */
final class MoE extends Facade
{
    private const CONTEXT_KEY = 'ai_agent.moe.gateway';

    private static ?MoEGateway $default = null;

    protected static function id(): string
    {
        return 'moe';
    }

    public static function getInstance(): object
    {
        return new self();
    }

    /**
     * 发送聊天（自动路由）
     */
    #[\NoDiscard]
    public function chat(string $message, array $options = []): ResponseInterface
    {
        return self::gateway()->chat($message, $options);
    }

    /**
     * 流式响应
     */
    #[\NoDiscard]
    public function stream(string $message, array $options = []): \Generator
    {
        return self::gateway()->stream($message, $options);
    }

    /**
     * 多模态
     */
    #[\NoDiscard]
    public function vision(string $text, array $images = [], array $options = []): ResponseInterface
    {
        return self::gateway()->vision($text, $images, $options);
    }

    /**
     * 获取网关实例
     */
    public function gateway(): MoEGateway
    {
        $gateway = KodeContext::get(self::CONTEXT_KEY);
        if ($gateway instanceof MoEGateway) {
            return $gateway;
        }

        if (self::$default === null) {
            self::$default = MoEBuilder::create()->build();
        }

        return self::$default;
    }

    /**
     * 添加专家
     */
    public function addExpert(
        string $platform,
        string|array $apiKey,
        array $capabilities = ['chat'],
        ?string $model = null,
        int $priority = 100,
        float $weight = 1.0,
        array $options = [],
    ): self {
        self::gateway()->addExpert($platform, $apiKey, $capabilities, $model, $priority, $weight, $options);
        return $this;
    }

    /**
     * 设置自定义网关
     */
    public function setGateway(MoEGateway $gateway): void
    {
        KodeContext::set(self::CONTEXT_KEY, $gateway);
        self::$default = $gateway;
    }

    /**
     * 获取报告
     */
    public function report(): array
    {
        return self::gateway()->report();
    }

    /**
     * 重置
     */
    public function reset(): void
    {
        KodeContext::delete(self::CONTEXT_KEY);
        self::$default = null;
    }
}
