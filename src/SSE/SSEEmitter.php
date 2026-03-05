<?php

declare(strict_types=1);

namespace Kode\AiAgent\SSE;

/**
 * SSE (Server-Sent Events) 流式响应
 * 
 * 实现 SSE 标准格式的流式响应输出。
 * 
 * @package Kode\AiAgent\SSE
 * 
 * @example
 * ```php
 * $sse = new SSEEmitter();
 * 
 * $sse->send('data', '{"content": "你好"}');
 * $sse->send('data', '{"content": "，世界"}');
 * $sse->close();
 * ```
 */
final class SSEEmitter
{
    private bool $started = false;
    private int $id = 0;
    private int $timeout = 60;

    public function __construct()
    {
        $this->start();
    }

    /**
     * 启动 SSE 流
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }

        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    /**
     * 发送事件
     *
     * @param string $event 事件类型
     * @param string $data 数据内容
     * @param int|null $id 事件ID
     */
    public function send(string $event, string $data, ?int $id = null): void
    {
        $this->ensureStarted();

        $id = $id ?? ++$this->id;

        echo "id: {$id}\n";
        echo "event: {$event}\n";
        echo "data: {$data}\n\n";

        $this->flush();
    }

    /**
     * 发送消息事件
     */
    public function message(string $content, ?int $id = null): void
    {
        $this->send('message', json_encode(['content' => $content], JSON_UNESCAPED_UNICODE), $id);
    }

    /**
     * 发送错误事件
     */
    public function error(string $message, int $code = 0): void
    {
        $this->send('error', json_encode([
            'code' => $code,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 发送完成事件
     */
    public function done(): void
    {
        $this->send('done', '{}');
    }

    /**
     * 发送心跳
     */
    public function ping(): void
    {
        echo ": ping\n\n";
        $this->flush();
    }

    /**
     * 发送注释 (不会触发前端事件)
     */
    public function comment(string $comment): void
    {
        echo ": {$comment}\n\n";
        $this->flush();
    }

    /**
     * 设置超时时间
     */
    public function withTimeout(int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * 关闭 SSE 流
     */
    public function close(): void
    {
        $this->done();
        $this->started = false;
    }

    /**
     * 确保已启动
     */
    private function ensureStarted(): void
    {
        if (!$this->started) {
            throw new \RuntimeException('SSE stream not started');
        }
    }

    /**
     * 刷新输出缓冲
     */
    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
