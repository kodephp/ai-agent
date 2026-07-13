<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

use Kode\AiAgent\Domain\Model\VideoResponse;
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;

/**
 * 视频供应商接口
 *
 * 统一视频生成供应商（Seedance、通义万相、数字人等）的能力契约。
 * 统一视频网关（VideoGateway）基于能力与成本在多个供应商之间自动路由。
 *
 * @package Kode\AiAgent\Domain\Contract
 *
 * @example
 * ```php
 * class MyVideoProvider implements VideoProviderInterface
 * {
 *     public function name(): string { return 'my-provider'; }
 *     public function model(): string { return 'my-model'; }
 *     public function supportedCapabilities(): array {
 *         return [MultimodalCapability::TEXT_TO_VIDEO];
 *     }
 *     public function textToVideo(string $prompt, array $options = []): VideoResponse { ... }
 *     public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse { ... }
 *     public function generateAvatar(string $text, array $options = []): VideoResponse { ... }
 *     public function getProgress(string $taskId): array { ... }
 *     public function estimateCost(array $options = []): float { ... }
 * }
 * ```
 */
interface VideoProviderInterface
{
    /**
     * 供应商名称（用于路由与统计）
     */
    public function name(): string;

    /**
     * 当前使用的模型名称
     */
    public function model(): string;

    /**
     * 支持的能力列表
     *
     * @return array<int, MultimodalCapability>
     */
    public function supportedCapabilities(): array;

    /**
     * 文本生成视频
     *
     * @param string $prompt 视频描述提示词
     * @param array<string, mixed> $options 选项（resolution/duration/aspect_ratio 等）
     */
    #[\NoDiscard]
    public function textToVideo(string $prompt, array $options = []): VideoResponse;

    /**
     * 图像生成视频
     *
     * @param string $image 输入图像（URL 或 base64）
     * @param string|null $prompt 视频描述提示词（可选）
     * @param array<string, mixed> $options 选项
     */
    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse;

    /**
     * 生成数字人视频
     *
     * 不支持该能力的供应商应抛出 PlatformException。
     *
     * @param string $text 驱动数字人的文本（口播/对话）
     * @param array<string, mixed> $options 选项（avatar_id/voice_id 等）
     */
    #[\NoDiscard]
    public function generateAvatar(string $text, array $options = []): VideoResponse;

    /**
     * 获取异步任务进度
     *
     * @param string $taskId 任务 ID
     * @return array<string, mixed> 任务状态（通常含 status / video_url / progress）
     */
    public function getProgress(string $taskId): array;

    /**
     * 估算单次生成成本（美元）
     *
     * @param array<string, mixed> $options 选项
     */
    public function estimateCost(array $options = []): float;
}
