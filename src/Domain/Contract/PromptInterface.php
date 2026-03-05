<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * 提示词接口
 * 
 * 定义 AI 提示词的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 */
interface PromptInterface
{
    /**
     * 获取文本内容
     *
     * @return string 文本
     */
    public function text(): string;

    /**
     * 获取图片列表
     *
     * @return array 图片数组
     */
    public function images(): array;

    /**
     * 检查是否为多模态
     *
     * @return bool 是否多模态
     */
    public function isMultimodal(): bool;

    /**
     * 转换为数组
     *
     * @return array 提示词数组
     */
    public function toArray(): array;
}
