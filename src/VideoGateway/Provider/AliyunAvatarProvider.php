<?php

declare(strict_types=1);

namespace Kode\AiAgent\VideoGateway\Provider;

use Kode\AiAgent\Domain\Contract\VideoProviderInterface;
use Kode\AiAgent\Domain\Model\VideoResponse;
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\Exception\PlatformException;
use Kode\HttpClient\Factory;
use Nyholm\Psr7\Request;

/**
 * 阿里数字人视频供应商
 *
 * 对接阿里云数字人（虚拟主播 / 数字人视频生成）服务。
 * 以"文本 + 数字人形象 + 音色"驱动生成口播视频，
 * 采用异步任务模式：提交任务 -> 轮询进度 -> 返回视频 URL。
 *
 * 端点可通过 options['base_url'] 配置以适配具体开通的数字人服务
 * （如阿里云智能媒体服务 / 数字人开放平台）。
 *
 * @package Kode\AiAgent\VideoGateway\Provider
 *
 * @example
 * ```php
 * $provider = new AliyunAvatarProvider('sk-dashscope-xxx');
 * $video = $provider->generateAvatar('大家好，欢迎使用数字人！', [
 *     'avatar_id' => 'default-female',
 *     'voice_id' => 'voice-female-zh',
 * ]);
 * echo $video->firstVideo();
 * ```
 */
final class AliyunAvatarProvider implements VideoProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://avatar.aliyuncs.com/api/v1/';
    private const DEFAULT_MODEL = 'aliyun-avatar';
    private const DEFAULT_COST = 0.20;

    private \Kode\HttpClient\HttpClient $client;
    private array $config;
    private string $apiKey;

    /**
     * @param array<string, mixed> $options base_url/avatar_id/voice_id/timeout 等
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \Kode\AiAgent\Exception\ConfigurationException('api_key 不能为空');
        }
        $this->apiKey = $apiKey;
        $this->config = $options;
        $this->client = Factory::create([
            'timeout' => $options['timeout'] ?? 120,
            'retries' => $options['retries'] ?? 3,
        ]);
    }

    public function name(): string
    {
        return 'aliyun_avatar';
    }

    public function model(): string
    {
        return self::DEFAULT_MODEL;
    }

    public function supportedCapabilities(): array
    {
        return [
            MultimodalCapability::AVATAR_GENERATION,
            MultimodalCapability::AVATAR_LIST,
            MultimodalCapability::VOICE_LIST,
            MultimodalCapability::ASYNC_GENERATION,
            MultimodalCapability::PROGRESS_TRACKING,
        ];
    }

    #[\NoDiscard]
    public function textToVideo(string $prompt, array $options = []): VideoResponse
    {
        throw new PlatformException('数字人供应商不支持文生视频，请使用 Seedance / 通义万相');
    }

    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
    {
        throw new PlatformException('数字人供应商不支持图生视频，请使用 Seedance / 通义万相');
    }

    #[\NoDiscard]
    public function generateAvatar(string $text, array $options = []): VideoResponse
    {
        if (trim($text) === '') {
            throw new \Kode\AiAgent\Exception\InvalidInputException('text 不能为空');
        }

        $body = [
            'avatar_id' => $options['avatar_id'] ?? $this->config['avatar_id'] ?? 'default-female',
            'voice_id' => $options['voice_id'] ?? $this->config['voice_id'] ?? 'default',
            'text' => $text,
            'resolution' => $options['resolution'] ?? $this->config['resolution'] ?? '1080p',
            'language' => $options['language'] ?? $this->config['language'] ?? 'zh-CN',
        ];

        $taskId = $this->submitTask('avatar/generate', $body);
        return $this->waitForCompletion($taskId, $options);
    }

    public function getProgress(string $taskId): array
    {
        try {
            $request = new Request('GET', $this->baseUrl() . 'avatar/tasks/' . $taskId, [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ]);
            $response = $this->client->sendRequest($request);
            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->baseUrl() . 'avatar/tasks/' . $taskId, $e);
        }
    }

    public function estimateCost(array $options = []): float
    {
        return self::DEFAULT_COST;
    }

    private function submitTask(string $path, array $body): string
    {
        try {
            $request = new Request('POST', $this->baseUrl() . $path, [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ], json_encode($body));

            $response = $this->client->sendRequest($request);
            $result = json_decode($response->getBody()->getContents(), true);

            $taskId = (string) ($result['task_id'] ?? $result['data']['task_id'] ?? '');
            if ($taskId === '') {
                throw new PlatformException('提交数字人任务失败：' . json_encode($result['message'] ?? $result));
            }

            return $taskId;
        } catch (PlatformException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->baseUrl() . $path, $e);
        }
    }

    private function waitForCompletion(string $taskId, array $options): VideoResponse
    {
        $timeout = (int) ($options['timeout'] ?? $this->config['timeout'] ?? 600);
        $interval = (int) ($options['poll_interval'] ?? 5);
        $start = time();

        while (time() - $start < $timeout) {
            $status = $this->getProgress($taskId);
            $state = strtoupper((string) ($status['status'] ?? $status['task_status'] ?? $status['output']['task_status'] ?? ''));

            if ($state === 'SUCCEEDED' || $state === 'COMPLETED' || $state === 'SUCCESS') {
                $videoUrl = (string) ($status['video_url'] ?? $status['data']['video_url'] ?? $status['output']['video_url'] ?? '');
                $duration = (float) ($status['video_duration'] ?? $status['data']['video_duration'] ?? 0);

                return (new VideoResponse(
                    videos: $videoUrl !== '' ? [$videoUrl] : [],
                    videoDuration: $duration,
                ))->with([
                    'requestId' => $taskId,
                    'model' => self::DEFAULT_MODEL,
                ]);
            }

            if ($state === 'FAILED' || $state === 'FAIL') {
                throw new PlatformException('数字人视频生成失败：' . json_encode($status['message'] ?? $status));
            }

            sleep($interval);
        }

        throw new PlatformException('数字人视频生成超时（task_id=' . $taskId . '）');
    }

    private function baseUrl(): string
    {
        return $this->config['base_url'] ?? self::DEFAULT_BASE_URL;
    }
}
