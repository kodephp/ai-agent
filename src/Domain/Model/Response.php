<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Model;

use Kode\AiAgent\Domain\Contract\ResponseInterface;
use Kode\Message\Message;

/**
 * AI 响应值对象
 * 
 * 使用 readonly 确保不可变性，通过 with() 方法创建新实例。
 * 
 * @package Kode\AiAgent\Domain\Model
 * 
 * @example
 * ```php
 * $response = new Response(
 *     content: '你好，世界！',
 *     choices: [...],
 *     usage: ['total_tokens' => 100],
 * );
 * 
 * $response = $response->with([
 *     'duration' => 0.123456,
 *     'requestId' => 'req-uuid-xxx',
 * ]);
 * 
 * echo $response->content();
 * echo $response->toJson();
 * ```
 */
readonly class Response implements ResponseInterface
{
    public function __construct(
        public string $content = '',
        public array $choices = [],
        public array $usage = [],
        public float $duration = 0.0,
        public int $code = 0,
        public string $msg = 'success',
        public string $requestId = '',
        public string $model = '',
        public bool $isStream = false,
    ) {}

    /**
     * 获取内容
     */
    public function content(): string
    {
        return $this->content;
    }

    /**
     * 获取选项
     */
    public function choices(): array
    {
        return $this->choices;
    }

    /**
     * 获取使用量
     */
    public function usage(): array
    {
        return $this->usage;
    }

    /**
     * 是否流式响应
     */
    public function isStream(): bool
    {
        return $this->isStream;
    }

    /**
     * 获取状态码
     */
    public function code(): int
    {
        return $this->code;
    }

    /**
     * 获取消息
     */
    public function msg(): string
    {
        return $this->msg;
    }

    /**
     * 获取耗时
     */
    public function duration(): float
    {
        return $this->duration;
    }

    /**
     * 检查是否成功
     */
    public function isSuccess(): bool
    {
        return $this->code === 0;
    }

    /**
     * 获取请求 ID
     */
    public function requestId(): string
    {
        return $this->requestId;
    }

    /**
     * 获取模型名称
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * 转换为数组
     * 开发者自定义字段与 code/msg/duration/data 同级
     */
    public function toArray(): array
    {
        $message = (new Message())
            ->code($this->code)
            ->msg($this->msg)
            ->data([
                'content' => $this->content,
                'choices' => $this->choices,
                'usage' => $this->usage,
            ])
            ->ext('duration', $this->duration);

        if ($this->requestId !== '') {
            $message->ext('request_id', $this->requestId);
        }
        if ($this->model !== '') {
            $message->ext('model', $this->model);
        }

        return $message->result();
    }

    /**
     * 转换为 JSON
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 创建新响应并修改指定字段
     * 
     * PHP 8.5+ 可使用 clone($this, $values) 语法
     * 
     * @example
     * $newResponse = $response->with(['duration' => 0.123, 'requestId' => 'uuid']);
     */
    public function with(array $values): static
    {
        $data = get_object_vars($this);
        return new self(
            content: $values['content'] ?? $data['content'],
            choices: $values['choices'] ?? $data['choices'],
            usage: $values['usage'] ?? $data['usage'],
            duration: $values['duration'] ?? $data['duration'],
            code: $values['code'] ?? $data['code'],
            msg: $values['msg'] ?? $data['msg'],
            requestId: $values['requestId'] ?? $values['request_id'] ?? $data['requestId'],
            model: $values['model'] ?? $data['model'],
            isStream: $values['isStream'] ?? $values['is_stream'] ?? $data['isStream'],
        );
    }

    /**
     * 获取 Token 使用量
     */
    public function tokenUsage(): array
    {
        return $this->usage;
    }

    /**
     * 魔术方法：直接输出内容
     */
    public function __toString(): string
    {
        return $this->content;
    }
}
