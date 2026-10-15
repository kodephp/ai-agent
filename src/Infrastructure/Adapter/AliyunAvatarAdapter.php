<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\{AvatarResponse, Progress};
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\Exception\PlatformException;
use Kode\AiAgent\VideoGateway\Provider\AliyunAvatarProvider;
use Kode\HttpClient\HttpClientInterface;

/**
 * 阿里数字人多模态适配器
 *
 * 把 {@see AliyunAvatarProvider}（提交 + 轮询的阿里数字人服务）桥接为统一的
 * 数字人契约（{@see \Kode\AiAgent\Domain\Contract\AvatarInterface}）：
 *
 *  - 同步生成：提交任务后由 provider 轮询至完成，映射为 {@see AvatarResponse}；
 *  - 异步生成：只提交拿任务 ID，进度走远端任务状态接口（不是本地假进度）；
 *  - 列表 / 自定义视频 / 自定义音频：继承基类默认实现（内置示例列表；未提供该能力的方法
 *    抛 1004 清晰异常）；
 *  - 对话能力：本平台不提供，{@see send()} / {@see stream()} 抛清晰异常。
 *
 * 经 {@see \Kode\AiAgent\Infrastructure\Adapter\AdapterFactory::create()} 的
 * 'aliyun-avatar' 别名创建，或 MultimodalBuilder::withPlatform('aliyun-avatar', [...]) 一步接入。
 *
 * @package Kode\AiAgent\Infrastructure\Adapter
 */
final class AliyunAvatarAdapter extends AbstractMultimodalAdapter implements AdapterInterface
{
    private const DEFAULT_AVATAR_ID = 'default-female';
    private const DEFAULT_VOICE_ID = 'default';

    private readonly AliyunAvatarProvider $provider;

    public function __construct(
        private readonly HttpClientInterface $client,
        private array $config = [],
    ) {
        $this->provider = new AliyunAvatarProvider(
            (string) ($this->config['api_key'] ?? ''),
            $this->config,
            $this->client,
        );

        parent::__construct();
    }

    /**
     * 自报能力：只认头像链路（fromAdapter 与桥接层据此精确注入，不虚报图文视频）。
     */
    #[\Override]
    protected function initializeCapabilities(): void
    {
        $this->addCapabilities([
            MultimodalCapability::AVATAR_GENERATION,
            MultimodalCapability::ASYNC_GENERATION,
            MultimodalCapability::PROGRESS_TRACKING,
            MultimodalCapability::AVATAR_LIST,
            MultimodalCapability::VOICE_LIST,
        ]);
    }

    #[\Override]
    #[\NoDiscard]
    public function name(): string
    {
        return 'aliyun-avatar';
    }

    #[\Override]
    #[\NoDiscard]
    public function send(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        throw new PlatformException(
            '阿里数字人平台仅提供数字人视频能力，不支持对话',
            1004,
            ['method' => 'send', 'platform' => $this->name()]
        );
    }

    #[\Override]
    #[\NoDiscard]
    public function stream(PromptInterface $prompt, array $options = []): \Generator
    {
        throw new PlatformException(
            '阿里数字人平台仅提供数字人视频能力，不支持对话流',
            1004,
            ['method' => 'stream', 'platform' => $this->name()]
        );
    }

    #[\Override]
    #[\NoDiscard]
    public function generateAvatarVideo(string $text, array $options = []): AvatarResponse
    {
        $video = $this->provider->generateAvatar($text, $options);

        return new AvatarResponse(
            video: $video->videos[0] ?? '',
            avatarId: (string) ($options['avatar_id'] ?? $this->config['avatar_id'] ?? self::DEFAULT_AVATAR_ID),
            voiceId: (string) ($options['voice_id'] ?? $this->config['voice_id'] ?? self::DEFAULT_VOICE_ID),
            text: $text,
            videoDuration: $video->videoDuration,
            duration: $video->duration,
            requestId: $video->requestId,
            model: $video->model,
        );
    }

    #[\Override]
    #[\NoDiscard]
    public function generateAvatarVideoAsync(string $text, array $options = []): string
    {
        // 真异步：提交拿任务 ID，不本地模拟；进度由 getProgress() 走远端任务接口，
        // 不写本地进度缓存（写了也永远不读，只会让常驻 worker 的缓存无界增长）
        return $this->provider->submitAvatarTask($text, $options);
    }

    #[\Override]
    #[\NoDiscard]
    public function getProgress(string $taskId): Progress
    {
        $raw = $this->provider->getProgress($taskId);
        if ($raw === []) {
            return Progress::create($taskId, '任务不存在')->withStatus(Progress::STATUS_FAILED);
        }

        $state = strtoupper((string) ($raw['status'] ?? $raw['task_status'] ?? $raw['output']['task_status'] ?? ''));
        $message = (string) ($raw['message'] ?? $raw['output']['message'] ?? '');

        [$status, $percent] = match ($state) {
            'SUCCEEDED', 'COMPLETED', 'SUCCESS' => [Progress::STATUS_COMPLETED, 100],
            'FAILED', 'FAIL' => [Progress::STATUS_FAILED, (int) ($raw['progress'] ?? 0)],
            default => [Progress::STATUS_PROCESSING, (int) ($raw['progress'] ?? 0)],
        };

        return new Progress(
            taskId: $taskId,
            status: $status,
            progress: $percent,
            message: $message,
            data: $raw,
        );
    }
}
