<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Model;

/**
 * 视频生成响应值对象
 * 
 * 使用 readonly 确保不可变性，通过 with() 方法创建新实例。
 * 
 * @package Kode\AiAgent\Domain\Model
 * 
 * @example
 * ```php
 * $response = new VideoResponse(
 *     videos: ['https://example.com/video1.mp4'],
 *     duration: 10.5,
 * );
 * 
 * $response = $response->with([
 *     'duration' => 45.678,
 *     'requestId' => 'vid-uuid-xxx',
 * ]);
 * 
 * echo $response->firstVideo();
 * echo $response->toJson();
 * ```
 */
final readonly class VideoResponse
{
    public function __construct(
        public array $videos = [],
        public float $videoDuration = 0.0,
        public int $width = 0,
        public int $height = 0,
        public float $duration = 0.0,
        public int $code = 0,
        public string $msg = 'success',
        public string $requestId = '',
        public string $model = '',
    ) {}

    /**
     * 获取所有视频 URL
     */
    public function videos(): array
    {
        return $this->videos;
    }

    /**
     * 获取第一个视频 URL
     */
    public function firstVideo(): string
    {
        return $this->videos[0] ?? '';
    }

    /**
     * 获取视频时长 (秒)
     */
    public function videoDuration(): float
    {
        return $this->videoDuration;
    }

    /**
     * 获取视频宽度
     */
    public function width(): int
    {
        return $this->width;
    }

    /**
     * 获取视频高度
     */
    public function height(): int
    {
        return $this->height;
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
                'videos' => $this->videos,
                'video_duration' => $this->videoDuration,
                'width' => $this->width,
                'height' => $this->height,
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
     * $newResponse = $response->with(['duration' => 45.678, 'requestId' => 'uuid']);
     */
    public function with(array $values): static
    {
        $data = get_object_vars($this);
        return new static(
            videos: $values['videos'] ?? $data['videos'],
            videoDuration: $values['videoDuration'] ?? $values['video_duration'] ?? $data['videoDuration'],
            width: $values['width'] ?? $data['width'],
            height: $values['height'] ?? $data['height'],
            duration: $values['duration'] ?? $data['duration'],
            code: $values['code'] ?? $data['code'],
            msg: $values['msg'] ?? $data['msg'],
            requestId: $values['requestId'] ?? $values['request_id'] ?? $data['requestId'],
            model: $values['model'] ?? $data['model'],
        );
    }

    /**
     * 魔术方法：输出第一个视频 URL
     */
    public function __toString(): string
    {
        return $this->firstVideo();
    }
}
