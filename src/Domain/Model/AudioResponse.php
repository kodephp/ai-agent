<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Model;

/**
 * 语音合成响应值对象
 *
 * 使用 readonly 确保不可变性，通过 with() 创建新实例。
 *
 * @package Kode\AiAgent\Domain\Model
 */
final readonly class AudioResponse
{
    public function __construct(
        public array $audios = [],
        public float $duration = 0.0,
        public string $voice = '',
        public string $model = '',
        public int $code = 0,
        public string $msg = 'success',
        public string $requestId = '',
    ) {}

    /**
     * 获取所有音频 URL / 路径
     *
     * @return array<int, string>
     */
    public function audios(): array
    {
        return $this->audios;
    }

    /**
     * 获取第一个音频 URL / 路径
     */
    public function firstAudio(): string
    {
        return $this->audios[0] ?? '';
    }

    public function isSuccess(): bool
    {
        return $this->code === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'audios' => $this->audios,
            'duration' => $this->duration,
            'voice' => $this->voice,
            'model' => $this->model,
            'code' => $this->code,
            'msg' => $this->msg,
            'requestId' => $this->requestId,
        ];
    }

    public function with(array $values): self
    {
        return new self(
            audios: $values['audios'] ?? $this->audios,
            duration: $values['duration'] ?? $this->duration,
            voice: $values['voice'] ?? $this->voice,
            model: $values['model'] ?? $this->model,
            code: $values['code'] ?? $this->code,
            msg: $values['msg'] ?? $this->msg,
            requestId: $values['requestId'] ?? $this->requestId,
        );
    }
}
