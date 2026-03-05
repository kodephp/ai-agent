<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support\Builder;

use Kode\AiAgent\Agent\Agent;
use Kode\AiAgent\Domain\Contract\AdapterInterface;
use Kode\AiAgent\Exception\ConfigurationException;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;

/**
 * Agent 构建器
 * 
 * 支持链式调用，灵活配置 Agent。
 * 
 * @package Kode\AiAgent\Support\Builder
 * 
 * @example
 * ```php
 * $agent = AgentBuilder::create()
 *     ->withPlatform('openai')
 *     ->withApiKey(env('OPENAI_API_KEY'))
 *     ->withModel('gpt-4')
 *     ->withTemperature(0.7)
 *     ->withTimeout(60)
 *     ->withRetry(3, 1000)
 *     ->withTool('calculator', '计算器', fn($a, $b) => $a + $b)
 *     ->withMiddleware(fn($prompt, $next) => $next($prompt))
 *     ->buildAgent();
 * ```
 */
final class AgentBuilder
{
    private string $platform = '';
    private array $config = [];
    private array $tools = [];
    private array $middlewares = [];
    private ?string $systemPrompt = null;
    private int $maxMessages = 50;
    private int $maxToolCalls = 5;

    private function __construct() {}

    /**
     * 创建构建器实例
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * 设置平台
     */
    public function withPlatform(string $platform): self
    {
        $this->platform = $platform;
        return $this;
    }

    /**
     * 设置 API Key
     */
    public function withApiKey(string $apiKey): self
    {
        $this->config['api_key'] = $apiKey;
        return $this;
    }

    /**
     * 设置模型
     */
    public function withModel(string $model): self
    {
        $this->config['model'] = $model;
        return $this;
    }

    /**
     * 设置温度参数
     */
    public function withTemperature(float $temperature): self
    {
        $this->config['temperature'] = $temperature;
        return $this;
    }

    /**
     * 设置超时时间
     */
    public function withTimeout(int $timeout): self
    {
        $this->config['timeout'] = $timeout;
        return $this;
    }

    /**
     * 设置重试策略
     */
    public function withRetry(int $maxAttempts, int $delay = 1000): self
    {
        $this->config['retry'] = [
            'max_attempts' => $maxAttempts,
            'delay' => $delay,
        ];
        return $this;
    }

    /**
     * 设置基础 URL
     */
    public function withBaseUrl(string $baseUrl): self
    {
        $this->config['base_url'] = $baseUrl;
        return $this;
    }

    /**
     * 设置系统提示词
     */
    public function withSystemPrompt(string $prompt): self
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    /**
     * 设置最大消息数量
     */
    public function withMaxMessages(int $max): self
    {
        $this->maxMessages = $max;
        return $this;
    }

    /**
     * 设置最大工具调用次数
     */
    public function withMaxToolCalls(int $max): self
    {
        $this->maxToolCalls = $max;
        return $this;
    }

    /**
     * 添加工具
     *
     * @param string $name 工具名称
     * @param string $description 工具描述
     * @param callable $handler 工具处理函数
     */
    public function withTool(string $name, string $description, callable $handler): self
    {
        $this->tools[] = [
            'name' => $name,
            'description' => $description,
            'handler' => $handler,
        ];
        return $this;
    }

    /**
     * 添加中间件
     */
    public function withMiddleware(callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * 设置自定义配置
     */
    public function withConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * 构建适配器
     *
     * @return AdapterInterface 适配器实例
     * @throws ConfigurationException 当配置不完整时
     */
    public function build(): AdapterInterface
    {
        $this->validate();

        return AdapterFactory::create($this->platform, $this->config);
    }

    /**
     * 构建 Agent 实例
     *
     * @return Agent Agent 实例
     * @throws ConfigurationException 当配置不完整时
     */
    public function buildAgent(): Agent
    {
        $adapter = $this->build();

        $agent = new Agent($adapter, [
            'system_prompt' => $this->systemPrompt,
            'max_messages' => $this->maxMessages,
            'max_tool_calls' => $this->maxToolCalls,
        ]);

        // 注册工具
        foreach ($this->tools as $tool) {
            $agent->registerTool(
                $tool['name'],
                $tool['description'],
                $tool['handler']
            );
        }

        return $agent;
    }

    /**
     * 验证配置
     */
    private function validate(): void
    {
        if ($this->platform === '') {
            throw ConfigurationException::missing('platform');
        }

        if (empty($this->config['api_key'])) {
            throw ConfigurationException::missing('api_key');
        }
    }

    /**
     * 获取配置
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * 获取平台
     */
    public function platform(): string
    {
        return $this->platform;
    }

    /**
     * 获取工具列表
     */
    public function tools(): array
    {
        return $this->tools;
    }

    /**
     * 获取中间件列表
     */
    public function middlewares(): array
    {
        return $this->middlewares;
    }
}
