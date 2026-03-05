<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Model;

use Kode\AiAgent\Domain\Contract\MessageInterface;

/**
 * 消息值对象
 * 
 * 表示聊天消息，支持系统、用户、助手和工具消息。
 * 
 * @package Kode\AiAgent\Domain\Model
 * 
 * @example
 * ```php
 * $message = new Message('user', '你好');
 * $message = Message::system('你是一个有用的助手');
 * $message = Message::tool('calculator', ['a' => 1, 'b' => 2], '3', 'call-123');
 * ```
 */
readonly class Message implements MessageInterface
{
    private function __construct(
        private string $role,
        private string $content,
        private ?string $name = null,
        private ?string $toolCallId = null,
        private ?array $toolCalls = null,
        private ?array $toolArguments = null,
    ) {}

    /**
     * 创建用户消息
     */
    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    /**
     * 创建系统消息
     */
    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    /**
     * 创建助手消息
     */
    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    /**
     * 创建工具调用消息
     */
    public static function toolCall(string $toolCallId, string $name, array $arguments): self
    {
        return new self(
            'assistant',
            '',
            null,
            null,
            [[
                'id' => $toolCallId,
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => $arguments,
                ],
            ]],
        );
    }

    /**
     * 创建工具结果消息
     */
    public static function toolResult(string $toolCallId, string $content): self
    {
        return new self(
            'tool',
            $content,
            null,
            $toolCallId,
        );
    }

    /**
     * 创建工具消息（完整版）
     * 
     * @param string $name 工具名称
     * @param array $arguments 工具参数
     * @param string $result 工具结果
     * @param string $toolCallId 调用 ID
     */
    public static function tool(string $name, array $arguments, string $result, string $toolCallId): self
    {
        return new self(
            'tool',
            $result,
            $name,
            $toolCallId,
            null,
            $arguments,
        );
    }

    public function role(): string
    {
        return $this->role;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function toolCallId(): ?string
    {
        return $this->toolCallId;
    }

    public function toolCalls(): ?array
    {
        return $this->toolCalls;
    }

    public function toolArguments(): ?array
    {
        return $this->toolArguments;
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        $result = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->name !== null) {
            $result['name'] = $this->name;
        }

        if ($this->toolCallId !== null) {
            $result['tool_call_id'] = $this->toolCallId;
        }

        if ($this->toolCalls !== null) {
            $result['tool_calls'] = $this->toolCalls;
        }

        return $result;
    }

    /**
     * 创建新消息并修改指定字段
     */
    public function with(array $values): static
    {
        return new self(
            $values['role'] ?? $this->role,
            $values['content'] ?? $this->content,
            $values['name'] ?? $this->name,
            $values['toolCallId'] ?? $this->toolCallId,
            $values['toolCalls'] ?? $this->toolCalls,
            $values['toolArguments'] ?? $this->toolArguments,
        );
    }
}
