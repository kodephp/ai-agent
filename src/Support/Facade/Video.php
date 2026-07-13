<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support\Facade;

use Kode\AiAgent\Domain\Model\VideoResponse;
use Kode\AiAgent\VideoGateway\VideoGateway;
use Kode\Context\Context as KodeContext;
use Kode\Facade\Facade;

/**
 * 统一视频网关门面
 *
 * 提供简洁的静态调用接口访问 VideoGateway，
 * 自动按能力在 Seedance / 通义万相 / 数字人 之间路由。
 *
 * @package Kode\AiAgent\Support\Facade
 *
 * @example
 * ```php
 * // 配置供应商
 * Video::addSeedance(env('VOLC_API_KEY'), ['version' => '2.5']);
 * Video::addWanxiang(env('DASHSCOPE_API_KEY'));
 * Video::addAliyunAvatar(env('DASHSCOPE_API_KEY'));
 *
 * // 文生视频（自动选最优）
 * $video = Video::textToVideo('一只猫咪在草地上玩耍');
 *
 * // 数字人
 * $avatar = Video::avatar('大家好，欢迎使用！');
 * ```
 *
 * @method static VideoResponse textToVideo(string $prompt, array $options = [])
 * @method static VideoResponse imageToVideo(string $image, ?string $prompt = null, array $options = [])
 * @method static VideoResponse avatar(string $text, array $options = [])
 * @method static array getProgress(string $taskId)
 * @method static array report()
 * @method static VideoGateway gateway()
 * @method static self addSeedance(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0)
 * @method static self addWanxiang(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0)
 * @method static self addAliyunAvatar(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0)
 * @method static void reset()
 */
final class Video extends Facade
{
    private const CONTEXT_KEY = 'ai_agent.video.gateway';

    private static ?VideoGateway $default = null;

    protected static function id(): string
    {
        return 'video';
    }

    public static function getInstance(): object
    {
        return new self();
    }

    public function gateway(): VideoGateway
    {
        $gateway = KodeContext::get(self::CONTEXT_KEY);
        if ($gateway instanceof VideoGateway) {
            return $gateway;
        }

        if (self::$default === null) {
            self::$default = new VideoGateway();
        }

        return self::$default;
    }

    #[\NoDiscard]
    public function textToVideo(string $prompt, array $options = []): VideoResponse
    {
        return self::gateway()->textToVideo($prompt, $options);
    }

    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
    {
        return self::gateway()->imageToVideo($image, $prompt, $options);
    }

    #[\NoDiscard]
    public function avatar(string $text, array $options = []): VideoResponse
    {
        return self::gateway()->avatar($text, $options);
    }

    public function getProgress(string $taskId): array
    {
        return self::gateway()->getProgress($taskId);
    }

    public function report(): array
    {
        return self::gateway()->report();
    }

    public function addSeedance(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0): self
    {
        self::gateway()->addSeedance($apiKey, $options, $priority, $weight);
        return $this;
    }

    public function addWanxiang(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0): self
    {
        self::gateway()->addWanxiang($apiKey, $options, $priority, $weight);
        return $this;
    }

    public function addAliyunAvatar(string $apiKey, array $options = [], int $priority = 100, float $weight = 1.0): self
    {
        self::gateway()->addAliyunAvatar($apiKey, $options, $priority, $weight);
        return $this;
    }

    /**
     * 设置自定义网关
     */
    public function setGateway(VideoGateway $gateway): void
    {
        KodeContext::set(self::CONTEXT_KEY, $gateway);
        self::$default = $gateway;
    }

    public function reset(): void
    {
        KodeContext::delete(self::CONTEXT_KEY);
        self::$default = null;
    }
}
