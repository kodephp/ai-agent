<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Model;

/**
 * 图像生成响应值对象
 * 
 * 使用 readonly 确保不可变性，通过 with() 方法创建新实例。
 * 
 * @package Kode\AiAgent\Domain\Model
 * 
 * @example
 * ```php
 * $response = new ImageResponse(
 *     images: ['https://example.com/image1.jpg'],
 *     revisedPrompt: '一只可爱的猫咪',
 * );
 * 
 * $response = $response->with([
 *     'duration' => 5.234,
 *     'requestId' => 'img-uuid-xxx',
 * ]);
 * 
 * echo $response->firstImage();
 * echo $response->toJson();
 * ```
 */
final readonly class ImageResponse
{
    public function __construct(
        public array $images = [],
        public string $revisedPrompt = '',
        public float $duration = 0.0,
        public int $code = 0,
        public string $msg = 'success',
        public string $requestId = '',
        public string $model = '',
    ) {}

    /**
     * 获取所有图像 URL
     */
    public function images(): array
    {
        return $this->images;
    }

    /**
     * 获取第一张图像 URL
     */
    public function firstImage(): string
    {
        return $this->images[0] ?? '';
    }

    /**
     * 获取修订后的提示词
     */
    public function revisedPrompt(): string
    {
        return $this->revisedPrompt;
    }

    /**
     * 获取耗时
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
                'images' => $this->images,
                'revised_prompt' => $this->revisedPrompt,
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
     * $newResponse = $response->with(['duration' => 5.234, 'requestId' => 'uuid']);
     */
    public function with(array $values): static
    {
        $data = get_object_vars($this);
        return new static(
            images: $values['images'] ?? $data['images'],
            revisedPrompt: $values['revisedPrompt'] ?? $values['revised_prompt'] ?? $data['revisedPrompt'],
            duration: $values['duration'] ?? $data['duration'],
            code: $values['code'] ?? $data['code'],
            msg: $values['msg'] ?? $data['msg'],
            requestId: $values['requestId'] ?? $values['request_id'] ?? $data['requestId'],
            model: $values['model'] ?? $data['model'],
        );
    }

    /**
     * 魔术方法：输出第一张图像 URL
     */
    public function __toString(): string
    {
        return $this->firstImage();
    }
}
