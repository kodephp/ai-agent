<?php

declare(strict_types=1);

namespace Kode\AiAgent\Application\Service;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PipelineInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\Prompt;

/**
 * AI Agent 服务
 * 
 * 提供管道式处理和中间件支持。
 * 
 * @package Kode\AiAgent\Application\Service
 * 
 * @example
 * ```php
 * $service = new AgentService($adapter);
 * 
 * // 添加中间件
 * $service->pipe(function ($prompt, $next) {
 *     echo "处理前: {$prompt->text()}\n";
 *     $response = $next($prompt);
 *     echo "处理后: {$response->content()}\n";
 *     return $response;
 * });
 * 
 * $response = $service->chat('你好');
 * ```
 */
final class AgentService implements PipelineInterface
{
    private array $stages = [];

    public function __construct(
        private AdapterInterface $adapter
    ) {}

    /**
     * 发送聊天消息
     *
     * @param string $message 消息内容
     * @param array $options 可选参数
     * @return ResponseInterface 响应对象
     */
    #[\NoDiscard]
    public function chat(string $message, array $options = []): ResponseInterface
    {
        return $this->generate(new Prompt($message), $options);
    }

    /**
     * 流式响应
     *
     * @param string $message 消息内容
     * @param array $options 可选参数
     * @return \Generator<string> 生成器
     */
    #[\NoDiscard]
    public function stream(string $message, array $options = []): \Generator
    {
        return $this->adapter->stream(new Prompt($message), $options);
    }

    /**
     * 生成响应
     *
     * @param PromptInterface $prompt 提示词
     * @param array $options 可选参数
     * @return ResponseInterface 响应对象
     */
    #[\NoDiscard]
    public function generate(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        $handler = function (PromptInterface $prompt) use ($options): ResponseInterface {
            return $this->adapter->send($prompt, $options);
        };

        $pipeline = array_reduce(
            array_reverse($this->stages),
            function (callable $next, callable $stage): callable {
                return function (PromptInterface $prompt) use ($stage, $next): ResponseInterface {
                    return $stage($prompt, $next);
                };
            },
            $handler
        );

        return $pipeline($prompt);
    }

    /**
     * @inheritDoc
     */
    public function pipe(callable $stage): static
    {
        $this->stages[] = $stage;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function process(mixed $input): mixed
    {
        if ($input instanceof PromptInterface) {
            return $this->generate($input);
        }

        if (is_string($input)) {
            return $this->chat($input);
        }

        throw new \InvalidArgumentException('输入必须是字符串或 PromptInterface 实例');
    }

    /**
     * @inheritDoc
     */
    public function reset(): static
    {
        $this->stages = [];
        return $this;
    }

    /**
     * 获取适配器
     */
    public function adapter(): AdapterInterface
    {
        return $this->adapter;
    }

    /**
     * 设置适配器
     */
    public function withAdapter(AdapterInterface $adapter): static
    {
        $this->adapter = $adapter;
        return $this;
    }
}
