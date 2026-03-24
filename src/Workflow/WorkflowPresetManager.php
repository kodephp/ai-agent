<?php

declare(strict_types=1);

namespace Kode\AiAgent\Workflow;

/**
 * 预设模板类型枚举
 */
enum PresetType: string
{
    case SHORT_DRAMA = 'short_drama';
    case PRODUCT_SHOWCASE = 'product_showcase';
    case EDUCATION = 'education';
    case NEWS = 'news';
    case SOCIAL_MEDIA = 'social_media';
    case VLOG = 'vlog';
    case COMMERCIAL = 'commercial';
    case MUSIC_VIDEO = 'music_video';
}

/**
 * 工作流预设模板
 */
final class WorkflowPreset
{
    public function __construct(
        public PresetType $type,
        public string $name,
        public string $description,
        public array $config,
    ) {}

    /**
     * 获取配置值
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * 合并配置
     */
    public function merge(array $overrides): self
    {
        return new self(
            type: $this->type,
            name: $this->name,
            description: $this->description,
            config: array_merge($this->config, $overrides),
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'name' => $this->name,
            'description' => $this->description,
            'config' => $this->config,
        ];
    }
}

/**
 * 工作流预设管理器
 *
 * 提供多种预定义工作流模板，简化短剧/视频生成配置。
 *
 * @package Kode\AiAgent\Workflow
 *
 * @example
 * ```php
 * $manager = new WorkflowPresetManager();
 *
 * // 获取短剧模板
 * $preset = $manager->get(PresetType::SHORT_DRAMA);
 *
 * // 应用模板生成短剧
 * $result = $agent->generate($script, $preset->config);
 *
 * // 自定义模板
 * $custom = $manager->createCustom('my_template', [
 *     'scenes' => 10,
 *     'transition_type' => 'fade',
 * ]);
 * ```
 */
final class WorkflowPresetManager
{
    /** @var WorkflowPreset[] */
    private array $presets = [];

    /** @var array<string, WorkflowPreset> 自定义模板 */
    private array $customPresets = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /**
     * 获取预设模板
     */
    public function get(PresetType $type): WorkflowPreset
    {
        return $this->presets[$type->value] ?? throw new \InvalidArgumentException("未知的预设类型: {$type->value}");
    }

    /**
     * 获取所有预设
     *
     * @return WorkflowPreset[]
     */
    public function all(): array
    {
        return array_merge($this->presets, $this->customPresets);
    }

    /**
     * 按类型筛选
     *
     * @return WorkflowPreset[]
     */
    public function filterByType(PresetType $type): array
    {
        return array_filter(
            $this->presets,
            fn($preset) => $preset->type === $type
        );
    }

    /**
     * 注册自定义模板
     */
    public function register(string $name, WorkflowPreset $preset): self
    {
        $this->customPresets[$name] = $preset;
        return $this;
    }

    /**
     * 创建自定义模板
     */
    public function createCustom(string $name, array $config, string $description = ''): WorkflowPreset
    {
        $preset = new WorkflowPreset(
            type: PresetType::SHORT_DRAMA,
            name: $name,
            description: $description,
            config: $config,
        );

        $this->customPresets[$name] = $preset;

        return $preset;
    }

    /**
     * 删除自定义模板
     */
    public function unregister(string $name): self
    {
        unset($this->customPresets[$name]);
        return $this;
    }

    /**
     * 检查模板是否存在
     */
    public function has(string $name): bool
    {
        return isset($this->customPresets[$name]);
    }

    /**
     * 注册默认模板
     */
    private function registerDefaults(): void
    {
        $this->presets = [
            PresetType::SHORT_DRAMA->value => new WorkflowPreset(
                type: PresetType::SHORT_DRAMA,
                name: '短剧模板',
                description: '适用于短视频平台的短剧生成，包含开场、结尾、转场效果',
                config: [
                    'scenes' => 5,
                    'duration_per_scene' => 10,
                    'style' => 'cinematic',
                    'transition_type' => 'fade',
                    'transition_duration' => 1,
                    'opening' => [
                        'title' => '精彩故事',
                        'duration' => 3,
                    ],
                    'closing' => [
                        'text' => '感谢观看',
                        'duration' => 5,
                    ],
                    'background_music' => true,
                    'subtitle' => true,
                    'image_size' => '1920x1080',
                    'video_resolution' => '1080p',
                ],
            ),

            PresetType::PRODUCT_SHOWCASE->value => new WorkflowPreset(
                type: PresetType::PRODUCT_SHOWCASE,
                name: '产品展示模板',
                description: '适用于电商产品展示视频生成',
                config: [
                    'scenes' => 8,
                    'duration_per_scene' => 5,
                    'style' => 'modern',
                    'transition_type' => 'slide_left',
                    'transition_duration' => 0.5,
                    'opening' => [
                        'title' => '产品介绍',
                        'duration' => 2,
                    ],
                    'closing' => [
                        'text' => '立即购买',
                        'duration' => 3,
                    ],
                    'background_music' => true,
                    'subtitle' => false,
                    'image_size' => '1080x1080',
                    'video_resolution' => '1080p',
                ],
            ),

            PresetType::EDUCATION->value => new WorkflowPreset(
                type: PresetType::EDUCATION,
                name: '教育视频模板',
                description: '适用于在线教育课程视频生成',
                config: [
                    'scenes' => 10,
                    'duration_per_scene' => 15,
                    'style' => 'professional',
                    'transition_type' => 'fade',
                    'transition_duration' => 1,
                    'opening' => [
                        'title' => '今日课程',
                        'duration' => 3,
                    ],
                    'closing' => [
                        'text' => '下节课见',
                        'duration' => 3,
                    ],
                    'background_music' => false,
                    'subtitle' => true,
                    'image_size' => '1920x1080',
                    'video_resolution' => '1080p',
                    'voice' => [
                        'role' => 'narrator',
                        'style' => 'professional',
                    ],
                ],
            ),

            PresetType::NEWS->value => new WorkflowPreset(
                type: PresetType::NEWS,
                name: '新闻视频模板',
                description: '适用于新闻播报视频生成',
                config: [
                    'scenes' => 6,
                    'duration_per_scene' => 20,
                    'style' => 'professional',
                    'transition_type' => 'dissolve',
                    'transition_duration' => 0.5,
                    'opening' => [
                        'title' => '新闻播报',
                        'duration' => 2,
                    ],
                    'closing' => [
                        'text' => '感谢收看',
                        'duration' => 2,
                    ],
                    'background_music' => false,
                    'subtitle' => true,
                    'image_size' => '1920x1080',
                    'video_resolution' => '1080p',
                    'voice' => [
                        'role' => 'narrator',
                        'style' => 'professional',
                    ],
                ],
            ),

            PresetType::SOCIAL_MEDIA->value => new WorkflowPreset(
                type: PresetType::SOCIAL_MEDIA,
                name: '社交媒体模板',
                description: '适用于抖音、快手等短视频平台',
                config: [
                    'scenes' => 3,
                    'duration_per_scene' => 15,
                    'style' => 'dynamic',
                    'transition_type' => 'zoom_in',
                    'transition_duration' => 0.3,
                    'opening' => [
                        'title' => '',
                        'duration' => 1,
                    ],
                    'closing' => [
                        'text' => '关注我',
                        'duration' => 2,
                    ],
                    'background_music' => true,
                    'subtitle' => true,
                    'image_size' => '1080x1920',
                    'video_resolution' => '1080p',
                ],
            ),

            PresetType::VLOG->value => new WorkflowPreset(
                type: PresetType::VLOG,
                name: 'Vlog模板',
                description: '适用于个人生活记录视频',
                config: [
                    'scenes' => 6,
                    'duration_per_scene' => 12,
                    'style' => 'natural',
                    'transition_type' => 'fade',
                    'transition_duration' => 1,
                    'opening' => [
                        'title' => '今日Vlog',
                        'duration' => 2,
                    ],
                    'closing' => [
                        'text' => '喜欢记得点赞',
                        'duration' => 3,
                    ],
                    'background_music' => true,
                    'subtitle' => true,
                    'image_size' => '1920x1080',
                    'video_resolution' => '1080p',
                ],
            ),

            PresetType::COMMERCIAL->value => new WorkflowPreset(
                type: PresetType::COMMERCIAL,
                name: '商业广告模板',
                description: '适用于品牌广告视频生成',
                config: [
                    'scenes' => 5,
                    'duration_per_scene' => 8,
                    'style' => 'cinematic',
                    'transition_type' => 'fade',
                    'transition_duration' => 1.5,
                    'opening' => [
                        'title' => '',
                        'duration' => 2,
                    ],
                    'closing' => [
                        'text' => '品牌名称',
                        'duration' => 3,
                    ],
                    'background_music' => true,
                    'subtitle' => false,
                    'image_size' => '1920x1080',
                    'video_resolution' => '1080p',
                    'color_grade' => 'professional',
                ],
            ),

            PresetType::MUSIC_VIDEO->value => new WorkflowPreset(
                type: PresetType::MUSIC_VIDEO,
                name: '音乐视频模板',
                description: '适用于音乐MV视频生成',
                config: [
                    'scenes' => 12,
                    'duration_per_scene' => 8,
                    'style' => 'dynamic',
                    'transition_type' => 'dissolve',
                    'transition_duration' => 1,
                    'opening' => [
                        'title' => '歌曲名',
                        'duration' => 3,
                    ],
                    'closing' => [
                        'text' => '歌手名',
                        'duration' => 5,
                    ],
                    'background_music' => false,
                    'subtitle' => true,
                    'image_size' => '1920x1080',
                    'video_resolution' => '1080p',
                    'beat_sync' => true,
                ],
            ),
        ];
    }

    /**
     * 获取模板类型列表
     */
    public function getTypes(): array
    {
        return array_map(fn($p) => $p->type->value, $this->presets);
    }

    /**
     * 获取模板名称列表
     */
    public function getNames(): array
    {
        return array_map(fn($p) => $p->name, $this->presets);
    }
}