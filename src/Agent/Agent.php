<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Kode\AiAgent\Attribute\Agent as AgentAttribute;
use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\{Message, Prompt};
use Kode\AiAgent\Support\Validator\InputValidator;
use Kode\AiAgent\Tool\ToolRegistry;
use Kode\Attributes\Reader;

/**
 * AI Agent 核心类
 * 
 * 提供完整的 AI Agent 功能，包括工具调用、对话管理、上下文处理。
 * 
 * @package Kode\AiAgent\Agent
 * 
 * @example
 * ```php
 * // 创建 Agent
 * $agent = new Agent($adapter, [
 *     'system_prompt' => '你是一个有用的助手',
 *     'max_tokens' => 4096,
 * ]);
 * 
 * // 发送消息
 * $response = $agent->chat('你好');
 * 
 * // 流式响应
 * foreach ($agent->stream('讲个故事') as $chunk) {
 *     echo $chunk;
 * }
 * 
 * // 注册工具
 * $agent->registerTool('calculator', '计算器', function (int $a, int $b): int {
 *     return $a + $b;
 * });
 * ```
 */
final class Agent
{
    private ToolRegistry $toolRegistry;
    private ?string $systemPrompt = null;
    private array $messages = [];
    private int $maxMessages = 50;
    private array $defaultOptions = [];
    private Reader $reader;
    private int $maxToolCalls = 5;
    private InputValidator $validator;

    public function __construct(
        private AdapterInterface $adapter,
        array $config = [],
        ?Reader $reader = null,
    ) {
        $this->reader = $reader ?? new Reader();
        $this->toolRegistry = new ToolRegistry($this->reader);
        $this->validator = new InputValidator();
        $this->systemPrompt = $config['system_prompt'] ?? null;
        $this->maxMessages = $config['max_messages'] ?? 50;
        $this->defaultOptions = $config['options'] ?? [];
        $this->maxToolCalls = $config['max_tool_calls'] ?? 5;

        if ($this->systemPrompt !== null) {
            $this->messages[] = Message::system($this->systemPrompt);
        }
    }

    /**
     * 从注解类创建 Agent
     */
    public static function fromClass(object $class, AdapterInterface $adapter, array $config = []): self
    {
        $reader = new Reader();
        $metaList = $reader->getAttributes($class);
        
        if ($metaList->has(AgentAttribute::class)) {
            $meta = $metaList->get(AgentAttribute::class);
            if ($meta !== null) {
                $agentAttr = $meta->getInstance();
                $config['system_prompt'] = $agentAttr->systemPrompt ?? $config['system_prompt'] ?? null;
            }
        }

        $agent = new self($adapter, $config, $reader);

        // 自动注册工具
        $agent->toolRegistry->registerFromClass($class);

        return $agent;
    }

    /**
     * 发送聊天消息（支持工具调用循环）
     */
    #[\NoDiscard]
    public function chat(string $message, array $options = []): ResponseInterface
    {
        $validatedMessage = $this->validator->validatePrompt($message);
        $validatedOptions = $this->validator->validateOptions($options);
        $this->addUserMessage($validatedMessage);

        $response = $this->adapter->send(
            $this->buildPrompt(),
            $this->mergeOptions($validatedOptions)
        );

        // 检查是否需要工具调用
        $toolCalls = $this->extractToolCalls($response);
        
        if (!empty($toolCalls)) {
            $response = $this->handleToolCalls($response, $validatedOptions);
        }

        $this->addAssistantMessage($response->content());
        $this->trimMessages();

        return $response;
    }

    /**
     * 流式响应
     */
    #[\NoDiscard]
    public function stream(string $message, array $options = []): \Generator
    {
        $validatedMessage = $this->validator->validatePrompt($message);
        $validatedOptions = $this->validator->validateOptions($options);
        $this->addUserMessage($validatedMessage);

        $fullContent = '';

        foreach ($this->adapter->stream($this->buildPrompt(), $this->mergeOptions($validatedOptions)) as $chunk) {
            $fullContent .= $chunk;
            yield $chunk;
        }

        $this->addAssistantMessage($fullContent);
        $this->trimMessages();
    }

    /**
     * 处理工具调用循环
     */
    private function handleToolCalls(ResponseInterface $response, array $options): ResponseInterface
    {
        $callCount = 0;

        while ($callCount < $this->maxToolCalls) {
            $toolCalls = $this->extractToolCalls($response);

            if (empty($toolCalls)) {
                break;
            }

            foreach ($toolCalls as $toolCall) {
                $toolName = $toolCall['name'] ?? $toolCall['function']['name'] ?? '';
                $toolArgs = $toolCall['arguments'] ?? $toolCall['function']['arguments'] ?? [];
                $toolId = $toolCall['id'] ?? '';

                // 执行工具
                try {
                    $result = $this->toolRegistry->execute($toolName, $toolArgs);
                    $toolResult = is_string($result) ? $result : json_encode($result);
                } catch (\Throwable $e) {
                    $toolResult = "错误: " . $e->getMessage();
                }

                // 添加工具调用结果到消息历史
                $this->addToolMessage($toolName, $toolArgs, $toolResult, $toolId);
            }

            // 再次调用 AI
            $response = $this->adapter->send(
                $this->buildPrompt(),
                $this->mergeOptions($options)
            );

            $callCount++;
        }

        return $response;
    }

    /**
     * 从响应中提取工具调用
     */
    private function extractToolCalls(ResponseInterface $response): array
    {
        $choices = $response->choices();
        
        if (empty($choices)) {
            return [];
        }

        $choice = $choices[0] ?? [];
        $message = $choice['message'] ?? $choice ?? [];
        
        return $message['tool_calls'] ?? [];
    }

    /**
     * 注册工具
     */
    public function registerTool(string $name, string $description, callable $handler): self
    {
        $this->toolRegistry->register($name, $description, $handler);
        return $this;
    }

    /**
     * 执行工具
     */
    public function executeTool(string $name, array $arguments): mixed
    {
        $validatedArguments = $this->validator->validateToolCall($name, $arguments);
        return $this->toolRegistry->execute($name, $validatedArguments);
    }

    /**
     * 获取工具注册表
     */
    public function tools(): ToolRegistry
    {
        return $this->toolRegistry;
    }

    /**
     * 获取消息历史
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * 清空消息历史
     */
    public function clearMessages(): self
    {
        $this->messages = [];
        
        if ($this->systemPrompt !== null) {
            $this->messages[] = Message::system($this->systemPrompt);
        }
        
        return $this;
    }

    /**
     * 设置系统提示词
     */
    public function withSystemPrompt(string $prompt): self
    {
        $this->systemPrompt = $prompt;
        
        if (!empty($this->messages) && $this->messages[0]->role() === 'system') {
            $this->messages[0] = Message::system($prompt);
        } else {
            array_unshift($this->messages, Message::system($prompt));
        }
        
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
     * 获取适配器
     */
    public function adapter(): AdapterInterface
    {
        return $this->adapter;
    }

    /**
     * 设置适配器
     */
    public function withAdapter(AdapterInterface $adapter): self
    {
        $this->adapter = $adapter;
        return $this;
    }

    /**
     * 添加用户消息
     */
    private function addUserMessage(string $content): void
    {
        $this->messages[] = Message::user($content);
    }

    /**
     * 添加助手消息
     */
    private function addAssistantMessage(string $content): void
    {
        $this->messages[] = Message::assistant($content);
    }

    /**
     * 添加工具调用消息
     */
    private function addToolMessage(string $name, array $args, string $result, string $id): void
    {
        $this->messages[] = Message::tool($name, $args, $result, $id);
    }

    /**
     * 构建提示词
     */
    private function buildPrompt(): PromptInterface
    {
        $lines = [];
        foreach ($this->messages as $message) {
            $lines[] = "{$message->role()}: {$message->content()}";
        }
        return new Prompt(implode("\n", $lines));
    }

    /**
     * 合并选项
     */
    private function mergeOptions(array $options): array
    {
        $merged = array_merge($this->defaultOptions, $options);

        // 如果有工具，添加到选项
        if ($this->toolRegistry->count() > 0) {
            $merged['tools'] = $this->toolRegistry->toOpenAIFormat();
        }

        return $merged;
    }

    /**
     * 裁剪消息历史
     */
    private function trimMessages(): void
    {
        if (count($this->messages) > $this->maxMessages) {
            $systemMessages = [];
            $otherMessages = [];

            foreach ($this->messages as $message) {
                if ($message->role() === 'system') {
                    $systemMessages[] = $message;
                } else {
                    $otherMessages[] = $message;
                }
            }

            $keepCount = $this->maxMessages - count($systemMessages);
            $otherMessages = array_slice($otherMessages, -$keepCount);

            $this->messages = array_merge($systemMessages, $otherMessages);
        }
    }
}
