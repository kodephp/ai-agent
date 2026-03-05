<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Model;

/**
 * 数字人生成响应值对象
 * 
 * 使用 readonly 确保不可变性，通过 with() 方法创建新实例。
 * 
 * @package Kode\AiAgent\Domain\Model
 * 
 * @example
 * ```php
 * $response = new AvatarResponse(
 *     video: 'https://example.com/avatar1.mp4',
 *     avatarId: 'avatar-001',
 *     voiceId: 'voice-001',
 * );
 * 
 * $response = $response->with([
 *     'duration' => 30.123,
 *     'requestId' => 'avatar-uuid-xxx',
 * ]);
 * 
 * echo $response->video();
 * echo $response->toJson();
 * ```
 */
readonly class AvatarResponse
{
    public function __construct(
        public string $video = '',
        public string $avatarId = '',
        public string $voiceId = '',
        public string $text = '',
        public float $videoDuration = 0.0,
        public float $duration = 0.0,
        public int $code = 0,
        public string $msg = 'success',
        public string $requestId = '',
        public string $model = '',
    ) {}

    /**
     * 获取视频 URL
     */
    public function video(): string
    {
        return $this->video;
    }

    /**
     * 获取数字人 ID
     */
    public function avatarId(): string
    {
        return $this->avatarId;
    }

    /**
     * 获取声音 ID
     */
    public function voiceId(): string
    {
        return $this->voiceId;
    }

    /**
     * 获取输入文本
     */
    public function text(): string
    {
        return $this->text;
    }

    /**
     * 获取视频时长 (秒)
     */
    public function videoDuration(): float
    {
        return $this->videoDuration;
    }

    /**
     * 获取生成耗时
     */
    public function duration(): float
    {
        return $this->duration;
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
        $result = [
            'code' => $this->code,
            'msg' => $this->msg,
            'duration' => $this->duration,
            'data' => [
                'video' => $this->video,
                'avatar_id' => $this->avatarId,
                'voice_id' => $this->voiceId,
                'text' => $this->text,
                'video_duration' => $this->videoDuration,
            ],
        ];

        if ($this->requestId !== '') {
            $result['request_id'] = $this->requestId;
        }
        if ($this->model !== '') {
            $result['model'] = $this->model;
        }

        return $result;
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
     * $newResponse = $response->with(['duration' => 30.123, 'requestId' => 'uuid']);
     */
    public function with(array $values): static
    {
        $data = get_object_vars($this);
        return new self(
            video: $values['video'] ?? $data['video'],
            avatarId: $values['avatarId'] ?? $values['avatar_id'] ?? $data['avatarId'],
            voiceId: $values['voiceId'] ?? $values['voice_id'] ?? $data['voiceId'],
            text: $values['text'] ?? $data['text'],
            videoDuration: $values['videoDuration'] ?? $values['video_duration'] ?? $data['videoDuration'],
            duration: $values['duration'] ?? $data['duration'],
            code: $values['code'] ?? $data['code'],
            msg: $values['msg'] ?? $data['msg'],
            requestId: $values['requestId'] ?? $values['request_id'] ?? $data['requestId'],
            model: $values['model'] ?? $data['model'],
        );
    }

    /**
     * 魔术方法：输出视频 URL
     */
    public function __toString(): string
    {
        return $this->video;
    }
}
