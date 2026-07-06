<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Model;

use Kode\AiAgent\Domain\Contract\PromptInterface;

/**
 * 提示词值对象
 * 
 * 使用 readonly 确保不可变性，通过 with() 方法创建新实例。
 * 
 * @package Kode\AiAgent\Domain\Model
 * 
 * @example
 * ```php
 * $prompt = new Prompt('你好，世界');
 * $prompt = new Prompt('描述这张图片', ['url' => 'https://example.com/image.png']);
 * ```
 */
final readonly class Prompt implements PromptInterface
{
    /**
     * @param string $text 文本内容
     * @param array $images 图片列表
     */
    public function __construct(
        private string $text,
        private array $images = [],
    ) {}

    public function text(): string
    {
        return $this->text;
    }

    public function images(): array
    {
        return $this->images;
    }

    public function isMultimodal(): bool
    {
        return !empty($this->images);
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'images' => $this->images,
        ];
    }

    /**
     * 创建新提示词并修改指定字段
     *
     * @param array $values 要修改的字段
     * @return static 新提示词实例
     */
    public function with(array $values): static
    {
        $data = get_object_vars($this);
        return new static(...array_merge($data, $values));
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
