<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\ValueObject;

/**
 * 多模态能力枚举
 * 
 * 定义平台支持的多模态能力类型。
 * 
 * @package Kode\AiAgent\Domain\ValueObject
 */
enum MultimodalCapability: string
{
    /**
     * 文本生成图像
     */
    case TEXT_TO_IMAGE = 'text_to_image';

    /**
     * 图像编辑
     */
    case IMAGE_EDIT = 'image_edit';

    /**
     * 图像变体生成
     */
    case IMAGE_VARIATION = 'image_variation';

    /**
     * 文本生成视频
     */
    case TEXT_TO_VIDEO = 'text_to_video';

    /**
     * 图像生成视频
     */
    case IMAGE_TO_VIDEO = 'image_to_video';

    /**
     * 数字人生成
     */
    case AVATAR_GENERATION = 'avatar_generation';

    /**
     * 自定义视频数字人
     */
    case AVATAR_CUSTOM_VIDEO = 'avatar_custom_video';

    /**
     * 自定义音频数字人
     */
    case AVATAR_CUSTOM_AUDIO = 'avatar_custom_audio';

    /**
     * 数字人列表
     */
    case AVATAR_LIST = 'avatar_list';

    /**
     * 声音列表
     */
    case VOICE_LIST = 'voice_list';

    /**
     * 异步生成
     */
    case ASYNC_GENERATION = 'async_generation';

    /**
     * 进度跟踪
     */
    case PROGRESS_TRACKING = 'progress_tracking';

    /**
     * 获取能力的友好名称
     */
    public function label(): string
    {
        return match ($this) {
            self::TEXT_TO_IMAGE => '文本生成图像',
            self::IMAGE_EDIT => '图像编辑',
            self::IMAGE_VARIATION => '图像变体',
            self::TEXT_TO_VIDEO => '文本生成视频',
            self::IMAGE_TO_VIDEO => '图像生成视频',
            self::AVATAR_GENERATION => '数字人生成',
            self::AVATAR_CUSTOM_VIDEO => '自定义视频数字人',
            self::AVATAR_CUSTOM_AUDIO => '自定义音频数字人',
            self::AVATAR_LIST => '数字人列表',
            self::VOICE_LIST => '声音列表',
            self::ASYNC_GENERATION => '异步生成',
            self::PROGRESS_TRACKING => '进度跟踪',
        };
    }

    /**
     * 获取能力的描述
     */
    public function description(): string
    {
        return match ($this) {
            self::TEXT_TO_IMAGE => '根据文本描述生成图像',
            self::IMAGE_EDIT => '编辑现有图像',
            self::IMAGE_VARIATION => '生成图像的变体',
            self::TEXT_TO_VIDEO => '根据文本描述生成视频',
            self::IMAGE_TO_VIDEO => '根据图像生成视频',
            self::AVATAR_GENERATION => '生成数字人视频',
            self::AVATAR_CUSTOM_VIDEO => '使用自定义视频生成数字人',
            self::AVATAR_CUSTOM_AUDIO => '使用自定义音频生成数字人',
            self::AVATAR_LIST => '获取可用数字人列表',
            self::VOICE_LIST => '获取可用声音列表',
            self::ASYNC_GENERATION => '支持异步任务生成',
            self::PROGRESS_TRACKING => '支持任务进度跟踪',
        };
    }

    /**
     * 检查是否是图像相关能力
     */
    public function isImage(): bool
    {
        return in_array($this, [
            self::TEXT_TO_IMAGE,
            self::IMAGE_EDIT,
            self::IMAGE_VARIATION,
        ], true);
    }

    /**
     * 检查是否是视频相关能力
     */
    public function isVideo(): bool
    {
        return in_array($this, [
            self::TEXT_TO_VIDEO,
            self::IMAGE_TO_VIDEO,
        ], true);
    }

    /**
     * 检查是否是数字人相关能力
     */
    public function isAvatar(): bool
    {
        return in_array($this, [
            self::AVATAR_GENERATION,
            self::AVATAR_CUSTOM_VIDEO,
            self::AVATAR_CUSTOM_AUDIO,
            self::AVATAR_LIST,
            self::VOICE_LIST,
        ], true);
    }
}
