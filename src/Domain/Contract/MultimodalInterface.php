<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

use Kode\AiAgent\Domain\Model\{AvatarResponse, ImageResponse, Progress, VideoResponse};
use Kode\AiAgent\Domain\ValueObject\{MediaFile, MultimodalCapability};
use Kode\AiAgent\Exception\PlatformException;
use Kode\AiAgent\Exception\InvalidInputException;

/**
 * 多模态统一接口
 * 
 * 整合图像生成、视频生成、数字人等所有多模态能力的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 */
interface MultimodalInterface extends
    ImageGeneratorInterface,
    VideoGeneratorInterface,
    AvatarInterface
{
    /**
     * 获取平台支持的所有能力
     *
     * @return MultimodalCapability[] 能力列表
     */
    #[\NoDiscard]
    public function capabilities(): array;

    /**
     * 检查平台是否支持指定能力
     *
     * @param MultimodalCapability $capability 能力
     *
     * @return bool 是否支持
     */
    #[\NoDiscard]
    public function supports(MultimodalCapability $capability): bool;

    /**
     * 获取平台名称
     *
     * @return string 平台名称
     */
    #[\NoDiscard]
    public function name(): string;

    /**
     * 智能生成（根据输入自动选择能力）
     *
     * @param string $prompt 提示词
     * @param array{
     *     output_type?: 'image'|'video'|'avatar',
     *     model?: string,
     * } $options 选项
     *
     * @return ImageResponse|VideoResponse|AvatarResponse 响应
     *
     * @throws PlatformException 当平台调用失败时
     * @throws InvalidInputException 当输入无效时
     */
    #[\NoDiscard]
    public function generate(string $prompt, array $options = []): mixed;
}
