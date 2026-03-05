<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * 向量嵌入接口
 * 
 * 定义文本向量化的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 * 
 * @example
 * ```php
 * $embedder = new OpenAIEmbedder($config);
 * 
 * // 单文本嵌入
 * $vector = $embedder->embed('你好，世界');
 * 
 * // 批量嵌入
 * $vectors = $embedder->embedBatch(['文本1', '文本2']);
 * ```
 */
interface EmbedderInterface
{
    /**
     * 将文本转换为向量
     *
     * @param string $text 输入文本
     * @return array 向量数组 (float[])
     */
    public function embed(string $text): array;

    /**
     * 批量将文本转换为向量
     *
     * @param array $texts 输入文本数组
     * @return array 向量数组 (array[][])
     */
    public function embedBatch(array $texts): array;

    /**
     * 获取向量维度
     *
     * @return int 向量维度
     */
    public function dimension(): int;

    /**
     * 获取嵌入模型名称
     *
     * @return string 模型名称
     */
    public function model(): string;
}
