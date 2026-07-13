<?php

declare(strict_types=1);

namespace Kode\AiAgent\VideoGateway\Provider;

use Kode\AiAgent\Domain\Contract\VideoProviderInterface;
use Kode\AiAgent\Domain\Model\VideoResponse;
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\Infrastructure\Adapter\SeedanceAdapter;
use Kode\HttpClient\Factory;

/**
 * Seedance 视频供应商
 *
 * 将字节跳动 Seedance（2.0 / 2.5）适配为统一视频供应商接口，
 * 支持文生视频、图生视频、多镜头叙事。版本可通过 options 配置。
 *
 * @package Kode\AiAgent\VideoGateway\Provider
 */
final class SeedanceVideoProvider implements VideoProviderInterface
{
    private SeedanceAdapter $adapter;
    private string $model;
    private array $defaultOptions;

    /** @var array<string, float> 模型单次成本（美元） */
    private const COST = [
        'seedance-2.0-pro' => 0.08,
        'seedance-2.0-lite' => 0.04,
        'seedance-2.5-pro' => 0.10,
        'seedance-2.5-lite' => 0.05,
    ];

    /**
     * @param array<string, mixed> $options version(2.0/2.5)/tier(pro/lite)/resolution/duration 等
     */
    public function __construct(string $apiKey, array $options = [])
    {
        $config = $options;
        $config['api_key'] = $apiKey;
        $config['timeout'] = $options['timeout'] ?? 120;
        $config['retries'] = $options['retries'] ?? 3;

        $this->adapter = SeedanceAdapter::create($apiKey, $config);

        $version = $options['version'] ?? '2.0';
        $tier = $options['tier'] ?? 'pro';
        $this->model = $options['model']
            ?? (SeedanceAdapter::VERSION_MODELS[$version][$tier] ?? SeedanceAdapter::VERSION_MODELS['2.0']['pro']);

        $this->defaultOptions = array_merge([
            'resolution' => '720p',
            'duration' => 10,
            'aspect_ratio' => '16:9',
            'fps' => 24,
            'version' => $version,
            'tier' => $tier,
        ], $options);
    }

    public function name(): string
    {
        return 'seedance';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function supportedCapabilities(): array
    {
        return [
            MultimodalCapability::TEXT_TO_VIDEO,
            MultimodalCapability::IMAGE_TO_VIDEO,
            MultimodalCapability::ASYNC_GENERATION,
            MultimodalCapability::PROGRESS_TRACKING,
        ];
    }

    #[\NoDiscard]
    public function textToVideo(string $prompt, array $options = []): VideoResponse
    {
        return $this->adapter->generateVideo($prompt, $this->merge($options));
    }

    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
    {
        return $this->adapter->imageToVideo($image, $prompt, $this->merge($options));
    }

    #[\NoDiscard]
    public function generateAvatar(string $text, array $options = []): VideoResponse
    {
        throw new \Kode\AiAgent\Exception\PlatformException(
            'Seedance 不支持数字人生成，请使用阿里数字人供应商'
        );
    }

    public function getProgress(string $taskId): array
    {
        return $this->adapter->getTaskStatus($taskId);
    }

    public function estimateCost(array $options = []): float
    {
        $model = $options['model'] ?? $this->model;
        $base = self::COST[$model] ?? 0.05;

        $resolution = strtolower((string) ($options['resolution'] ?? $this->defaultOptions['resolution']));
        if ($resolution === '1080p') {
            $base *= 1.2;
        }

        $duration = (float) ($options['duration'] ?? $this->defaultOptions['duration']);
        if ($duration > 0) {
            $base *= $duration / 10;
        }

        return round($base, 4);
    }

    private function merge(array $options): array
    {
        $merged = array_merge($this->defaultOptions, $options);
        if (($merged['model'] ?? '') === '') {
            $merged['model'] = $this->model;
        }
        return $merged;
    }
}
