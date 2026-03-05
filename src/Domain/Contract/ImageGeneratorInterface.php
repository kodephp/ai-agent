<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * 图像生成接口
 * 
 * 定义文本生成图像的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 */
interface ImageGeneratorInterface
{
    /**
     * 生成图像
     *
     * @param string $prompt 图像描述提示词
     * @param array{
     *     model?: string,
     *     size?: string,
     *     n?: int,
     *     quality?: string,
     *     style?: string,
     *     response_format?: string,
     * } $options 可选参数
     *
     * @return \Kode\AiAgent\Domain\Model\ImageResponse 图像响应
     *
     * @throws \Kode\AiAgent\Exception\PlatformException 当平台调用失败时
     * @throws \Kode\AiAgent\Exception\InvalidInputException 当输入无效时
     */
    #[\NoDiscard]
    public function generateImage(string $prompt, array $options = []): \Kode\AiAgent\Domain\Model\ImageResponse;

    /**
     * 编辑图像
     *
     * @param string $image 原始图像 (base64 或 URL)
     * @param string $prompt 编辑描述提示词
     * @param array{
     *     model?: string,
     *     mask?: string,
     *     size?: string,
     *     n?: int,
     * } $options 可选参数
     *
     * @return \Kode\AiAgent\Domain\Model\ImageResponse 图像响应
     */
    #[\NoDiscard]
    public function editImage(string $image, string $prompt, array $options = []): \Kode\AiAgent\Domain\Model\ImageResponse;

    /**
     * 图像变体生成
     *
     * @param string $image 原始图像 (base64 或 URL)
     * @param array{
     *     model?: string,
     *     size?: string,
     *     n?: int,
     * } $options 可选参数
     *
     * @return \Kode\AiAgent\Domain\Model\ImageResponse 图像响应
     */
    #[\NoDiscard]
    public function generateImageVariation(string $image, array $options = []): \Kode\AiAgent\Domain\Model\ImageResponse;
}
