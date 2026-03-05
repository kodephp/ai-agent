<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * 视频生成接口
 * 
 * 定义文本生成视频的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 */
interface VideoGeneratorInterface
{
    /**
     * 生成视频
     *
     * @param string $prompt 视频描述提示词
     * @param array{
     *     model?: string,
     *     duration?: float,
     *     fps?: int,
     *     resolution?: string,
     *     aspect_ratio?: string,
     * } $options 可选参数
     *
     * @return \Kode\AiAgent\Domain\Model\VideoResponse 视频响应
     *
     * @throws \Kode\AiAgent\Exception\PlatformException 当平台调用失败时
     * @throws \Kode\AiAgent\Exception\InvalidInputException 当输入无效时
     */
    #[\NoDiscard]
    public function generateVideo(string $prompt, array $options = []): \Kode\AiAgent\Domain\Model\VideoResponse;

    /**
     * 从图像生成视频
     *
     * @param string $image 输入图像 (base64 或 URL)
     * @param string|null $prompt 视频描述提示词 (可选)
     * @param array{
     *     model?: string,
     *     duration?: float,
     *     fps?: int,
     * } $options 可选参数
     *
     * @return \Kode\AiAgent\Domain\Model\VideoResponse 视频响应
     */
    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): \Kode\AiAgent\Domain\Model\VideoResponse;
}
