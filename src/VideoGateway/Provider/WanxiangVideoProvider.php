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
 * 阿里通义万相（Wanxiang）视频供应商
 *
 * 对接阿里云百炼 / 通义万相视频生成 API：
 * - 文生视频：wanx2.1-t2v-plus / wanx2.1-t2v-turbo
 * - 图生视频：wanx2.1-i2v-plus / wanx2.1-i2v-turbo
 *
 * 接口为异步任务模式：提交任务 -> 轮询任务状态 -> 返回视频 URL。
 *
 * @package Kode\AiAgent\VideoGateway\Provider
 *
 * @example
 * ```php
 * $provider = new WanxiangVideoProvider('sk-dashscope-xxx');
 * $video = $provider->textToVideo('一只猫咪在草地上玩耍', ['duration' => 5]);
 * echo $video->firstVideo();
 * ```
 */
final class WanxiangVideoProvider implements VideoProviderInterface
{
    private const BASE_URL = 'https://dashscope.aliyuncs.com/api/v1/';
    private const DEFAULT_MODEL_T2V = 'wanx2.1-t2v-plus';
    private const DEFAULT_MODEL_I2V = 'wanx2.1-i2v-plus';

    private const SIZES = [
        '720p' => '1280*720',
        '1080p' => '1920*1080',
        '480p' => '854*480',
    ];

    /** @var array<string, float> 模型单次成本（美元） */
    private const COST = [
        'wanx2.1-t2v-plus' => 0.07,
        'wanx2.1-t2v-turbo' => 0.03,
        'wanx2.1-i2v-plus' => 0.09,
        'wanx2.1-i2v-turbo' => 0.04,
    ];

    private \Kode\HttpClient\HttpClient $client;
    private array $config;
    private string $apiKey;

    /**
     * @param array<string, mixed> $options resolution/duration/aspect_ratio/base_url 等
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
        return 'wanxiang';
    }

    public function model(): string
    {
        return $this->config['model'] ?? self::DEFAULT_MODEL_T2V;
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
        $model = $options['model'] ?? self::DEFAULT_MODEL_T2V;
        $body = [
            'model' => $model,
            'input' => ['prompt' => $prompt],
            'parameters' => $this->buildParameters($options),
        ];

        $taskId = $this->submitTask('video-generation/video-synthesis', $body);
        return $this->waitForCompletion($taskId, $model, $options);
    }

    #[\NoDiscard]
    public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
    {
        $model = $options['model'] ?? self::DEFAULT_MODEL_I2V;
        $input = ['img_url' => $this->resolveImage($image)];
        if ($prompt !== null && $prompt !== '') {
            $input['prompt'] = $prompt;
        }

        $body = [
            'model' => $model,
            'input' => $input,
            'parameters' => $this->buildParameters($options),
        ];

        $taskId = $this->submitTask('video-generation/video-synthesis', $body);
        return $this->waitForCompletion($taskId, $model, $options);
    }

    #[\NoDiscard]
    public function generateAvatar(string $text, array $options = []): VideoResponse
    {
        throw new PlatformException('通义万相不支持数字人生成，请使用阿里数字人供应商');
    }

    public function getProgress(string $taskId): array
    {
        try {
            $request = new Request('GET', $this->baseUrl() . 'tasks/' . $taskId, [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ]);
            $response = $this->client->sendRequest($request);
            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->baseUrl() . 'tasks/' . $taskId, $e);
        }
    }

    public function estimateCost(array $options = []): float
    {
        $model = $options['model'] ?? self::DEFAULT_MODEL_T2V;
        return self::COST[$model] ?? 0.06;
    }

    private function buildParameters(array $options): array
    {
        $resolution = strtolower($options['resolution'] ?? $this->config['resolution'] ?? '720p');
        $size = self::SIZES[$resolution] ?? self::SIZES['720p'];

        $params = [
            'size' => $size,
            'duration' => (string) ($options['duration'] ?? $this->config['duration'] ?? 5),
        ];

        if (isset($options['seed'])) {
            $params['seed'] = $options['seed'];
        }

        return $params;
    }

    private function submitTask(string $path, array $body): string
    {
        try {
            $request = new Request('POST', $this->baseUrl() . $path, [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'X-DashScope-Async' => 'enable',
            ], json_encode($body));

            $response = $this->client->sendRequest($request);
            $result = json_decode($response->getBody()->getContents(), true);

            $taskId = $result['output']['task_id'] ?? '';
            if ($taskId === '') {
                throw new PlatformException('提交视频任务失败：' . json_encode($result['message'] ?? $result));
            }

            return $taskId;
        } catch (PlatformException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed($this->baseUrl() . $path, $e);
        }
    }

    private function waitForCompletion(string $taskId, string $model, array $options): VideoResponse
    {
        $timeout = (int) ($options['timeout'] ?? $this->config['timeout'] ?? 600);
        $interval = (int) ($options['poll_interval'] ?? 5);
        $start = time();

        while (time() - $start < $timeout) {
            $status = $this->getProgress($taskId);
            $taskStatus = strtoupper((string) ($status['output']['task_status'] ?? ''));

            if ($taskStatus === 'SUCCEEDED') {
                $output = $status['output'] ?? [];
                $videoUrl = $output['video_url'] ?? '';
                $duration = (float) ($output['video_duration'] ?? $options['duration'] ?? 5);

                return (new VideoResponse(
                    videos: $videoUrl !== '' ? [$videoUrl] : [],
                    videoDuration: $duration,
                ))->with([
                    'requestId' => $status['request_id'] ?? $taskId,
                    'model' => $model,
                ]);
            }

            if ($taskStatus === 'FAILED') {
                throw new PlatformException('视频生成失败：' . json_encode($status['output']['message'] ?? $status));
            }

            sleep($interval);
        }

        throw new PlatformException('视频生成超时（task_id=' . $taskId . '）');
    }

    private function resolveImage(string $image): string
    {
        if (filter_var($image, FILTER_VALIDATE_URL)) {
            return $image;
        }
        if (str_starts_with($image, 'data:image/')) {
            return $image;
        }
        if (file_exists($image)) {
            $content = file_get_contents($image);
            $mime = mime_content_type($image);
            return 'data:' . $mime . ';base64,' . base64_encode($content);
        }
        return $image;
    }

    private function baseUrl(): string
    {
        return $this->config['base_url'] ?? self::BASE_URL;
    }
}
