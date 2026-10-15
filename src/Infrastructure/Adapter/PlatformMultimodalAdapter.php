<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, AvatarInterface, ImageGeneratorInterface, VideoGeneratorInterface};
use Kode\AiAgent\Domain\Model\{AvatarResponse, ImageResponse, Progress, VideoResponse};
use Kode\AiAgent\Domain\ValueObject\{MediaFile, MultimodalCapability};
use Kode\AiAgent\Exception\{ConfigurationException, PlatformException};

/**
 * 平台多模态桥接适配器
 *
 * 把只实现部分能力接口的平台适配器（OpenAiAdapter 实现 ImageGeneratorInterface、
 * SeedanceAdapter 实现 VideoGeneratorInterface 等）桥接为统一的 MultimodalInterface，
 * 使其可直接接入 MultimodalService / Multimodal 门面 / MultimodalBuilder /
 * ShortDramaTeam / DramAgentV2 等整条多模态链路。
 *
 * 能力列表按注入的委托自动推断（也可用 $explicitCapabilities 显式覆盖）：
 *
 * - 图像委托存在  → TEXT_TO_IMAGE + IMAGE_EDIT + IMAGE_VARIATION
 * - 视频委托存在  → TEXT_TO_VIDEO + IMAGE_TO_VIDEO
 * - 数字人委托存在 → AVATAR_GENERATION + AVATAR_CUSTOM_VIDEO + AVATAR_CUSTOM_AUDIO
 *                    + ASYNC_GENERATION + PROGRESS_TRACKING
 * - 基类默认实现的 AVATAR_LIST / VOICE_LIST 恒可用（无委托时返回内置默认列表）
 *
 * @example
 * ```php
 * // 方式一：从平台适配器自动桥接
 * $bridge = PlatformMultimodalAdapter::fromAdapter(AdapterFactory::create('openai', ['api_key' => 'sk-xxx']));
 *
 * // 方式二：显式注入各能力委托（可混合不同平台）
 * $bridge = new PlatformMultimodalAdapter(
 *     imageGenerator: $openAi,
 *     videoGenerator: $seedance,
 *     platformName: 'openai+seedance',
 * );
 *
 * // 方式三：Builder 一步到位
 * $service = MultimodalBuilder::create()->withPlatform('openai', ['api_key' => 'sk-xxx'])->build();
 * ```
 *
 * @package Kode\AiAgent\Infrastructure\Adapter
 */
final class PlatformMultimodalAdapter extends AbstractMultimodalAdapter
{
    /**
     * @param ImageGeneratorInterface|null $imageGenerator 图像生成委托（文生图/图像编辑/图像变体）
     * @param VideoGeneratorInterface|null $videoGenerator 视频生成委托（文生视频/图生视频）
     * @param AvatarInterface|null $avatarProvider 数字人委托（同步/异步/自定义数字人、列表）
     * @param string $platformName 平台名称（name() 返回值，用于日志与路由展示）
     * @param MultimodalCapability[]|null $explicitCapabilities 显式能力列表；null 表示按委托自动推断
     */
    public function __construct(
        private readonly ?ImageGeneratorInterface $imageGenerator = null,
        private readonly ?VideoGeneratorInterface $videoGenerator = null,
        private readonly ?AvatarInterface $avatarProvider = null,
        private readonly string $platformName = 'platform',
        private readonly ?array $explicitCapabilities = null,
    ) {
        if ($imageGenerator === null && $videoGenerator === null && $avatarProvider === null) {
            throw new ConfigurationException(
                '平台多模态适配器至少需要一个能力委托（imageGenerator / videoGenerator / avatarProvider）',
                101,
                ['method' => '__construct']
            );
        }

        parent::__construct();
    }

    /**
     * 从平台适配器自动桥接（按其实现的能力接口注入委托）
     *
     * @param object $adapter 平台适配器（如 AdapterFactory::create() 的产物）
     * @param string $platformName 平台名称；空字符串时取适配器 name()，再退化为 'platform'
     *
     * @throws ConfigurationException 当适配器未实现任何多模态能力接口时
     */
    public static function fromAdapter(object $adapter, string $platformName = ''): self
    {
        if ($platformName === '') {
            $platformName = $adapter instanceof AdapterInterface ? $adapter->name() : 'platform';
        }

        // 多模态家族（AbstractMultimodalAdapter 子类）：MultimodalInterface 本身就 extends
        // 三个子接口，instanceof 探测在这一族上恒真 —— 直接嗅探会把「仅数字人」的适配器
        // 虚报成图文视频全能力。这一族改为读自报能力挑委托：
        //  - 已声明能力（initializeCapabilities 非空）→ 按 supports() 精确注入，能力表原样带入；
        //  - 未声明能力（沿用基类空表的完整多模态自定义适配器）→ 三个委托都给，沿用「完整实现」语义。
        if ($adapter instanceof AbstractMultimodalAdapter) {
            $caps = $adapter->capabilities();
            if ($caps !== []) {
                $by = static fn (MultimodalCapability $capability): ?object
                    => $adapter->supports($capability) ? $adapter : null;

                return new self(
                    imageGenerator: $by(MultimodalCapability::TEXT_TO_IMAGE),
                    videoGenerator: $by(MultimodalCapability::TEXT_TO_VIDEO),
                    avatarProvider: $by(MultimodalCapability::AVATAR_GENERATION),
                    platformName: $platformName,
                    explicitCapabilities: $caps,
                );
            }

            return new self(
                imageGenerator: $adapter,
                videoGenerator: $adapter,
                avatarProvider: $adapter,
                platformName: $platformName,
            );
        }

        if (!$adapter instanceof ImageGeneratorInterface
            && !$adapter instanceof VideoGeneratorInterface
            && !$adapter instanceof AvatarInterface
        ) {
            throw new ConfigurationException(
                '适配器 ' . $adapter::class . ' 未实现 ImageGeneratorInterface / VideoGeneratorInterface / AvatarInterface 中的任何一个，无法桥接为多模态适配器',
                101,
                ['adapter' => $adapter::class]
            );
        }

        return new self(
            imageGenerator: $adapter instanceof ImageGeneratorInterface ? $adapter : null,
            videoGenerator: $adapter instanceof VideoGeneratorInterface ? $adapter : null,
            avatarProvider: $adapter instanceof AvatarInterface ? $adapter : null,
            platformName: $platformName,
        );
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function initializeCapabilities(): void
    {
        if ($this->explicitCapabilities !== null) {
            // SORT_REGULAR：能力枚举对象不能走默认的字符串比较（会触发枚举转 string 的 Error）
            $this->supportedCapabilities = array_values(array_unique($this->explicitCapabilities, SORT_REGULAR));
            return;
        }

        $capabilities = [];

        if ($this->imageGenerator !== null) {
            $capabilities[] = MultimodalCapability::TEXT_TO_IMAGE;
            $capabilities[] = MultimodalCapability::IMAGE_EDIT;
            $capabilities[] = MultimodalCapability::IMAGE_VARIATION;
        }

        if ($this->videoGenerator !== null) {
            $capabilities[] = MultimodalCapability::TEXT_TO_VIDEO;
            $capabilities[] = MultimodalCapability::IMAGE_TO_VIDEO;
        }

        if ($this->avatarProvider !== null) {
            $capabilities[] = MultimodalCapability::AVATAR_GENERATION;
            $capabilities[] = MultimodalCapability::AVATAR_CUSTOM_VIDEO;
            $capabilities[] = MultimodalCapability::AVATAR_CUSTOM_AUDIO;
            $capabilities[] = MultimodalCapability::ASYNC_GENERATION;
            $capabilities[] = MultimodalCapability::PROGRESS_TRACKING;
        }

        // 基类自带默认实现（内置列表），无数字人委托时也可用
        $capabilities[] = MultimodalCapability::AVATAR_LIST;
        $capabilities[] = MultimodalCapability::VOICE_LIST;

        $this->supportedCapabilities = $capabilities;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function name(): string
    {
        return $this->platformName;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function generateImage(string $prompt, array $options = []): ImageResponse
    {
        if ($this->imageGenerator === null) {
            throw $this->unsupported('generateImage', MultimodalCapability::TEXT_TO_IMAGE);
        }

        return $this->imageGenerator->generateImage($prompt, $options);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function editImage(string $image, string $prompt, array $options = []): ImageResponse
    {
        if ($this->imageGenerator === null) {
            throw $this->unsupported('editImage', MultimodalCapability::IMAGE_EDIT);
        }

        return $this->imageGenerator->editImage($image, $prompt, $options);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function generateImageVariation(string $image, array $options = []): ImageResponse
    {
        if ($this->imageGenerator === null) {
            throw $this->unsupported('generateImageVariation', MultimodalCapability::IMAGE_VARIATION);
        }

        return $this->imageGenerator->generateImageVariation($image, $options);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function generateVideo(string $prompt, array $options = []): VideoResponse
    {
        if ($this->videoGenerator === null) {
            throw $this->unsupported('generateVideo', MultimodalCapability::TEXT_TO_VIDEO);
        }

        return $this->videoGenerator->generateVideo($prompt, $options);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
    {
        if ($this->videoGenerator === null) {
            throw $this->unsupported('imageToVideo', MultimodalCapability::IMAGE_TO_VIDEO);
        }

        return $this->videoGenerator->imageToVideo($image, $prompt, $options);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function generateAvatarVideo(string $text, array $options = []): AvatarResponse
    {
        return $this->avatar()->generateAvatarVideo($text, $options);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function generateWithCustomVideo(string $text, MediaFile $videoFile, array $options = []): AvatarResponse
    {
        return $this->avatar()->generateWithCustomVideo($text, $videoFile, $options);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function generateWithCustomAudio(MediaFile $audioFile, array $options = []): AvatarResponse
    {
        return $this->avatar()->generateWithCustomAudio($audioFile, $options);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function generateAvatarVideoAsync(string $text, array $options = []): string
    {
        return $this->avatar()->generateAvatarVideoAsync($text, $options);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function getProgress(string $taskId): Progress
    {
        if ($this->avatarProvider === null) {
            // 无数字人委托时退化为基类的本地进度缓存行为（恒「任务不存在」）
            return parent::getProgress($taskId);
        }

        return $this->avatarProvider->getProgress($taskId);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function listAvatars(array $options = []): array
    {
        if ($this->avatarProvider === null) {
            return parent::listAvatars($options);
        }

        return $this->avatarProvider->listAvatars($options);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function listVoices(array $options = []): array
    {
        if ($this->avatarProvider === null) {
            return parent::listVoices($options);
        }

        return $this->avatarProvider->listVoices($options);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function getAvatar(string $avatarId): array
    {
        if ($this->avatarProvider === null) {
            return parent::getAvatar($avatarId);
        }

        return $this->avatarProvider->getAvatar($avatarId);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    #[\NoDiscard]
    public function getVoice(string $voiceId): array
    {
        if ($this->avatarProvider === null) {
            return parent::getVoice($voiceId);
        }

        return $this->avatarProvider->getVoice($voiceId);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getDownloadPrompt(AvatarResponse $response): string
    {
        if ($this->avatarProvider === null) {
            return parent::getDownloadPrompt($response);
        }

        return $this->avatarProvider->getDownloadPrompt($response);
    }

    /** 取数字人委托（缺失时抛出精确错误，而非基类的「暂未实现」）。 */
    private function avatar(): AvatarInterface
    {
        if ($this->avatarProvider === null) {
            throw $this->unsupported('generateAvatarVideo', MultimodalCapability::AVATAR_GENERATION);
        }

        return $this->avatarProvider;
    }

    /** 无委托时的统一「能力未配置」错误（区别于基类的「暂未实现」占位错误）。 */
    private function unsupported(string $method, MultimodalCapability $capability): PlatformException
    {
        return new PlatformException(
            "当前平台未配置{$capability->label()}能力（委托缺失）",
            1004,
            ['method' => $method, 'capability' => $capability->value, 'platform' => $this->platformName]
        );
    }
}
