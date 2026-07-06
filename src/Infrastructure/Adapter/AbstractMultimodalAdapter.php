<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Domain\Contract\MultimodalInterface;
use Kode\AiAgent\Domain\Model\{AvatarResponse, ImageResponse, Progress, VideoResponse};
use Kode\AiAgent\Domain\ValueObject\{MediaFile, MultimodalCapability};
use Kode\AiAgent\Exception\PlatformException;
use Kode\AiAgent\Exception\InvalidInputException;

/**
 * 抽象多模态适配器
 * 
 * 提供多模态适配器的基础实现，支持能力发现和智能生成。
 * 整合了图像生成、视频生成、数字人等所有多模态能力。
 * 
 * @package Kode\AiAgent\Infrastructure\Adapter
 */
abstract class AbstractMultimodalAdapter implements MultimodalInterface
{
    /**
     * @var MultimodalCapability[] 平台支持的能力列表
     */
    protected array $supportedCapabilities = [];

    /**
     * @var array<string, Progress> 任务进度缓存
     */
    protected array $progressCache = [];

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->initializeCapabilities();
    }

    /**
     * 初始化平台支持的能力
     * 
     * 子类应该重写此方法来设置支持的能力
     */
    protected function initializeCapabilities(): void
    {
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function capabilities(): array
    {
        return $this->supportedCapabilities;
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function supports(MultimodalCapability $capability): bool
    {
        return in_array($capability, $this->supportedCapabilities, true);
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function generate(string $prompt, array $options = []): mixed
    {
        $outputType = $options['output_type'] ?? 'image';

        return match ($outputType) {
            'image' => $this->generateImage($prompt, $options),
            'video' => $this->generateVideo($prompt, $options),
            'avatar' => $this->generateAvatarVideo($prompt, $options),
            // @phpstan-ignore-next-line 防御性默认分支，处理运行时非预期值
            default => throw new InvalidInputException("不支持的输出类型: {$outputType}"),
        };
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function generateImage(string $prompt, array $options = []): ImageResponse
    {
        throw new PlatformException(
            '文本生成图像功能暂未实现',
            1004,
            ['method' => 'generateImage', 'capability' => MultimodalCapability::TEXT_TO_IMAGE->value]
        );
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function editImage(string $image, string $prompt, array $options = []): ImageResponse
    {
        throw new PlatformException(
            '图像编辑功能暂未实现',
            1004,
            ['method' => 'editImage', 'capability' => MultimodalCapability::IMAGE_EDIT->value]
        );
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function generateImageVariation(string $image, array $options = []): ImageResponse
    {
        throw new PlatformException(
            '图像变体生成功能暂未实现',
            1004,
            ['method' => 'generateImageVariation', 'capability' => MultimodalCapability::IMAGE_VARIATION->value]
        );
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function generateVideo(string $prompt, array $options = []): VideoResponse
    {
        throw new PlatformException(
            '文本生成视频功能暂未实现',
            1004,
            ['method' => 'generateVideo', 'capability' => MultimodalCapability::TEXT_TO_VIDEO->value]
        );
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
    {
        throw new PlatformException(
            '图像生成视频功能暂未实现',
            1004,
            ['method' => 'imageToVideo', 'capability' => MultimodalCapability::IMAGE_TO_VIDEO->value]
        );
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    abstract public function generateAvatarVideo(string $text, array $options = []): AvatarResponse;

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function generateWithCustomVideo(string $text, MediaFile $videoFile, array $options = []): AvatarResponse
    {
        throw new PlatformException(
            '自定义视频数字人功能暂未实现',
            1004,
            ['method' => 'generateWithCustomVideo', 'capability' => MultimodalCapability::AVATAR_CUSTOM_VIDEO->value]
        );
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function generateWithCustomAudio(MediaFile $audioFile, array $options = []): AvatarResponse
    {
        throw new PlatformException(
            '自定义音频数字人功能暂未实现',
            1004,
            ['method' => 'generateWithCustomAudio', 'capability' => MultimodalCapability::AVATAR_CUSTOM_AUDIO->value]
        );
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function generateAvatarVideoAsync(string $text, array $options = []): string
    {
        $taskId = $this->generateTaskId();
        $progress = Progress::create($taskId, '任务已创建，正在排队');
        $this->progressCache[$taskId] = $progress;

        $this->scheduleAsyncTask($taskId, $text, $options);

        return $taskId;
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function getProgress(string $taskId): Progress
    {
        return $this->progressCache[$taskId] ?? Progress::create($taskId, '任务不存在')->withStatus(Progress::STATUS_FAILED);
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function listAvatars(array $options = []): array
    {
        return [
            [
                'id' => 'default-female',
                'name' => '默认女性数字人',
                'gender' => 'female',
                'category' => 'business',
                'preview_url' => '',
            ],
            [
                'id' => 'default-male',
                'name' => '默认男性数字人',
                'gender' => 'male',
                'category' => 'business',
                'preview_url' => '',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function listVoices(array $options = []): array
    {
        return [
            [
                'id' => 'voice-female-zh',
                'name' => '中文女声',
                'language' => 'zh-CN',
                'gender' => 'female',
                'style' => 'natural',
            ],
            [
                'id' => 'voice-male-zh',
                'name' => '中文男声',
                'language' => 'zh-CN',
                'gender' => 'male',
                'style' => 'natural',
            ],
            [
                'id' => 'voice-female-en',
                'name' => '英文女声',
                'language' => 'en-US',
                'gender' => 'female',
                'style' => 'natural',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function getAvatar(string $avatarId): array
    {
        $avatars = $this->listAvatars();
        foreach ($avatars as $avatar) {
            if ($avatar['id'] === $avatarId) {
                return $avatar;
            }
        }
        throw new PlatformException("数字人不存在: {$avatarId}", 1004, ['avatar_id' => $avatarId]);
    }

    /**
     * @inheritDoc
     */
    #[\NoDiscard]
    public function getVoice(string $voiceId): array
    {
        $voices = $this->listVoices();
        foreach ($voices as $voice) {
            if ($voice['id'] === $voiceId) {
                return $voice;
            }
        }
        throw new PlatformException("声音不存在: {$voiceId}", 1004, ['voice_id' => $voiceId]);
    }

    /**
     * @inheritDoc
     */
    public function getDownloadPrompt(AvatarResponse $response): string
    {
        $prompt = <<<TEXT
🎬 您的数字人视频已生成成功！

📦 视频信息：
   • 视频链接: {$response->video()}
   • 数字人ID: {$response->avatarId()}
   • 声音ID: {$response->voiceId()}
   • 视频时长: {$response->videoDuration()}秒

💡 重要提示：
   1. 请点击上方链接下载视频
   2. 建议使用浏览器右键"另存为"保存视频
   3. 视频链接有有效期，请尽快下载
   4. 如需再次生成，请重新提交请求

如有问题，请联系技术支持。
TEXT;

        return $prompt;
    }

    /**
     * 添加支持的能力
     */
    protected function addCapability(MultimodalCapability $capability): void
    {
        if (!in_array($capability, $this->supportedCapabilities, true)) {
            $this->supportedCapabilities[] = $capability;
        }
    }

    /**
     * 批量添加支持的能力
     *
     * @param MultimodalCapability[] $capabilities
     */
    protected function addCapabilities(array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            $this->addCapability($capability);
        }
    }

    /**
     * 确保能力被支持
     *
     * @throws PlatformException 当能力不支持时
     */
    protected function ensureCapability(MultimodalCapability $capability): void
    {
        if (!$this->supports($capability)) {
            throw new PlatformException(
                "当前平台不支持能力: {$capability->label()}",
                1004,
                ['capability' => $capability->value]
            );
        }
    }

    /**
     * 生成任务 ID
     */
    protected function generateTaskId(): string
    {
        return 'multimodal_' . bin2hex(random_bytes(16));
    }

    /**
     * 更新任务进度
     */
    protected function updateProgress(string $taskId, string $status, int $progress = 0, string $message = '', ?array $data = null): void
    {
        if (isset($this->progressCache[$taskId])) {
            $current = $this->progressCache[$taskId];
            $this->progressCache[$taskId] = $current
                ->withStatus($status, $message, $data)
                ->withProgress($progress);
        }
    }

    /**
     * 调度异步任务
     */
    protected function scheduleAsyncTask(string $taskId, string $text, array $options): void
    {
        $this->updateProgress($taskId, Progress::STATUS_PROCESSING, 10, '正在处理请求...');

        register_shutdown_function(function () use ($taskId, $text, $options) {
            try {
                $this->updateProgress($taskId, Progress::STATUS_GENERATING, 50, '正在生成视频...');
                $response = $this->generateAvatarVideo($text, $options);
                $this->updateProgress($taskId, Progress::STATUS_COMPLETED, 100, '视频生成完成', [
                    'video_url' => $response->video(),
                    'response' => $response->toArray(),
                ]);
            } catch (\Throwable $e) {
                $this->updateProgress($taskId, Progress::STATUS_FAILED, 0, '生成失败: ' . $e->getMessage(), [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 创建数字人响应
     */
    protected function createAvatarResponse(
        string $videoUrl,
        string $avatarId,
        string $voiceId,
        string $text,
        float $videoDuration,
        float $duration,
        string $model = ''
    ): AvatarResponse {
        return new AvatarResponse(
            video: $videoUrl,
            avatarId: $avatarId,
            voiceId: $voiceId,
            text: $text,
            videoDuration: $videoDuration,
            duration: $duration,
            model: $model,
        );
    }
}
