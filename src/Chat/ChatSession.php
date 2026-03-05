<?php

declare(strict_types=1);

namespace Kode\AiAgent\Chat;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, MessageInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\{Message, Prompt};

/**
 * 对话管理器
 * 
 * 管理对话历史和消息上下文。
 * 
 * @package Kode\AiAgent\Chat
 * 
 * @example
 * ```php
 * $chat = new ChatSession($adapter);
 * 
 * $chat->addSystemMessage('你是一个有用的助手');
 * $response = $chat->send('你好');
 * 
 * // 继续对话
 * $response = $chat->send('请继续');
 * ```
 */
final class ChatSession
{
    private array $messages = [];
    private int $maxMessages = 50;

    public function __construct(
        private AdapterInterface $adapter,
        private ?string $systemPrompt = null,
    ) {
        if ($systemPrompt !== null) {
            $this->messages[] = Message::system($systemPrompt);
        }
    }

    /**
     * 添加系统消息
     */
    public function addSystemMessage(string $content): self
    {
        $this->messages[] = Message::system($content);
        return $this;
    }

    /**
     * 添加用户消息
     */
    public function addUserMessage(string $content): self
    {
        $this->messages[] = Message::user($content);
        return $this;
    }

    /**
     * 添加助手消息
     */
    public function addAssistantMessage(string $content): self
    {
        $this->messages[] = Message::assistant($content);
        return $this;
    }

    /**
     * 发送消息并获取响应
     */
    #[\NoDiscard]
    public function send(string $message, array $options = []): ResponseInterface
    {
        $this->addUserMessage($message);
        
        $response = $this->adapter->send(
            $this->buildPrompt(),
            $options
        );

        $this->addAssistantMessage($response->content());
        $this->trimMessages();

        return $response;
    }

    /**
     * 流式发送消息
     */
    #[\NoDiscard]
    public function stream(string $message, array $options = []): \Generator
    {
        $this->addUserMessage($message);

        $fullContent = '';
        
        foreach ($this->adapter->stream($this->buildPrompt(), $options) as $chunk) {
            $fullContent .= $chunk;
            yield $chunk;
        }

        $this->addAssistantMessage($fullContent);
        $this->trimMessages();
    }

    /**
     * 获取消息历史
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * 获取消息数量
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * 清空消息历史
     */
    public function clear(): self
    {
        $this->messages = [];
        
        if ($this->systemPrompt !== null) {
            $this->messages[] = Message::system($this->systemPrompt);
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
     * 导出对话历史
     */
    public function export(): array
    {
        return array_map(
            fn(MessageInterface $m): array => $m->toArray(),
            $this->messages
        );
    }

    /**
     * 构建提示词
     */
    private function buildPrompt(): Prompt
    {
        $lines = [];
        foreach ($this->messages as $message) {
            $lines[] = "{$message->role()}: {$message->content()}";
        }
        return new Prompt(implode("\n", $lines));
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
