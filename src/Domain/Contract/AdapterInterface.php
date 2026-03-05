<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * 平台适配器接口
 * 
 * 定义与 AI 模型提供商交互的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 */
interface AdapterInterface
{
    /**
     * 发送同步请求
     *
     * @param PromptInterface $prompt 提示词
     * @param array{
     *     model?: string,
     *     temperature?: float,
     *     max_tokens?: int,
     *     stream?: bool
     * } $options 可选参数
     *
     * @return ResponseInterface 统一响应
     *
     * @throws \Kode\AiAgent\Exception\PlatformException 当平台调用失败时
     * @throws \Kode\AiAgent\Exception\TimeoutException 当请求超时时
     * @throws \Kode\AiAgent\Exception\RateLimitException 当触发频率限制时
     */
    #[\NoDiscard]
    public function send(PromptInterface $prompt, array $options = []): ResponseInterface;

    /**
     * 发送流式请求
     *
     * @param PromptInterface $prompt 提示词
     * @param array $options 可选参数
     *
     * @return \Generator<string> 生成器
     *
     * @throws \Kode\AiAgent\Exception\PlatformException 当平台调用失败时
     */
    #[\NoDiscard]
    public function stream(PromptInterface $prompt, array $options = []): \Generator;
    
    /**
     * 获取适配器名称
     *
     * @return string 适配器标识
     */
    public function name(): string;
}
