<?php

declare(strict_types=1);

namespace Kode\AiAgent\AudioGateway\Provider;

use Kode\AiAgent\Domain\Contract\TtsProviderInterface;
use Kode\AiAgent\Domain\Model\AudioResponse;
use Kode\AiAgent\Exception\{AuthenticationException, ConfigurationException, PlatformException, RateLimitException};
use Kode\HttpClient\HttpClientInterface;
use Nyholm\Psr7\Request;

/**
 * OpenAI 文字转语音供应商
 *
 * 使用 OpenAI 最新的 gpt-4o-mini-tts（自然度顶尖、支持用自然语言
 * instructions 控制语气 / 风格），输出 mp3 并保存到本地。
 *
 * 该供应商即"最像真人说话"的默认 TTS 实现；如需替换为火山 COSYVoice /
 * 阿里 CosyVoice，实现 TtsProviderInterface 并注册到 AudioGateway 即可。
 *
 * @package Kode\AiAgent\AudioGateway\Provider
 */
final class OpenAiTtsProvider implements TtsProviderInterface
{
    private const BASE_URL = 'https://api.openai.com/v1/audio/speech';
    private const DEFAULT_MODEL = 'gpt-4o-mini-tts';
    private const DEFAULT_VOICE = 'alloy';

    /**
     * 默认"自然说话"风格指令（让语调更像真人，避免机械感）
     */
    private const DEFAULT_INSTRUCTIONS = '用自然、口语化、像真人在讲述的方式朗读，'
        . '语速适中，在标点处短暂停顿，带一点情绪起伏，不要机械念稿。';

    /** @var array<string, float> 每千字符成本（美元，近似） */
    private const COST_PER_1K_CHARS = [
        'gpt-4o-mini-tts' => 0.00015,
        'tts-1' => 0.00015,
        'tts-1-hd' => 0.0003,
    ];

    private HttpClientInterface $client;
    private string $apiKey;
    private string $model;
    private string $voice;
    private string $instructions;
    private array $defaultOptions;

    /**
     * @param array<string, mixed> $options model / voice / instructions / output_dir / timeout / retries
     * @param HttpClientInterface|null $client 测试可注入假客户端
     */
    public function __construct(string $apiKey, array $options = [], ?HttpClientInterface $client = null)
    {
        if (empty($apiKey)) {
            throw ConfigurationException::missing('api_key');
        }

        $this->apiKey = $apiKey;

        if ($client === null) {
            $client = \Kode\HttpClient\Factory::create([
                'timeout' => $options['timeout'] ?? 120,
                'retries' => $options['retries'] ?? 3,
            ]);
        }

        $this->client = $client;
        $this->model = $options['model'] ?? self::DEFAULT_MODEL;
        $this->voice = $options['voice'] ?? self::DEFAULT_VOICE;
        $this->instructions = $options['instructions'] ?? self::DEFAULT_INSTRUCTIONS;

        $this->defaultOptions = array_merge([
            'output_dir' => $options['output_dir'] ?? 'var/drama/audio',
            'response_format' => 'mp3',
            'speed' => 1.0,
        ], $options);
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function supportedVoices(): array
    {
        return ['alloy', 'ash', 'coral', 'echo', 'fable', 'onyx', 'nova', 'sage', 'shimmer'];
    }

    #[\NoDiscard]
    public function synthesize(string $text, array $options = []): AudioResponse
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('TTS 合成文本不能为空');
        }

        $voice = $options['voice'] ?? $this->voice;
        $model = $options['model'] ?? $this->model;
        $instructions = $options['instructions'] ?? $this->instructions;
        $format = $options['response_format'] ?? $this->defaultOptions['response_format'];
        $speed = (float) ($options['speed'] ?? $this->defaultOptions['speed']);

        $body = [
            'model' => $model,
            'input' => $text,
            'voice' => $voice,
            'response_format' => $format,
            'speed' => $speed,
        ];

        if ($instructions !== '') {
            $body['instructions'] = $instructions;
        }

        $outputPath = $this->savePath($format);

        try {
            $request = new Request(
                'POST',
                self::BASE_URL,
                [
                    'Authorization' => 'Bearer ' . $this->resolveApiKey(),
                    'Content-Type' => 'application/json',
                ],
                (string) json_encode($body)
            );

            $response = $this->client->sendRequest($request);
            $status = $response->getStatusCode();

            if ($status === 401) {
                throw AuthenticationException::invalidApiKey('openai');
            }
            if ($status === 429) {
                throw RateLimitException::requestsPerMinute(0);
            }
            if ($status >= 400) {
                throw PlatformException::serverError($status, $response->getReasonPhrase());
            }

            $audio = $response->getBody()->getContents();
            if ($audio === '' || file_put_contents($outputPath, $audio) === false) {
                throw PlatformException::responseParseFailed('TTS 音频写入失败');
            }

            return new AudioResponse(
                audios: [$outputPath],
                duration: $this->estimateDuration($text, $speed),
                voice: $voice,
                model: $model,
            );
        } catch (AuthenticationException | RateLimitException | PlatformException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed(self::BASE_URL, $e);
        }
    }

    public function estimateCost(array $options = []): float
    {
        $model = $options['model'] ?? $this->model;
        $rate = self::COST_PER_1K_CHARS[$model] ?? 0.00015;
        $chars = mb_strlen((string) ($options['text'] ?? ''));

        return round($rate * max(1, $chars) / 1000, 6);
    }

    private function resolveApiKey(): string
    {
        // apiKey 经由构造函数注入；此处从 client 不可得，故存于属性
        return $this->apiKey;
    }

    private function savePath(string $format): string
    {
        $dir = $this->defaultOptions['output_dir'];
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return sprintf('%s/tts_%s.%s', $dir, date('Ymd-His') . '-' . bin2hex(random_bytes(4)), $format);
    }

    private function estimateDuration(string $text, float $speed): float
    {
        // 中文约 4 字/秒，英文约 14 字符/秒的粗略估算
        $chars = mb_strlen($text);
        $cjk = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text);
        $rate = $cjk > 0 ? 4.0 : 14.0;

        return round($chars / $rate / max(0.1, $speed), 2);
    }
}
