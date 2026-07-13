<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\AudioGateway\Provider\OpenAiTtsProvider;
use Kode\AiAgent\Domain\Model\AudioResponse;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * OpenAI TTS 供应商测试（注入假 HttpClient，不触网）
 */
final class OpenAiTtsProviderTest extends TestCase
{
    private function fakeClient(string $body, int $status = 200, string $reason = 'OK'): \Kode\HttpClient\HttpClientInterface
    {
        return new class($body, $status, $reason) implements \Kode\HttpClient\HttpClientInterface {
            public function __construct(
                private string $body,
                private int $status,
                private string $reason,
            ) {}

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response($this->status, [], $this->body, '1.1', $this->reason);
            }

            public function sendRequestWithContext(\Psr\Http\Message\RequestInterface $request, mixed $context = null): \Psr\Http\Message\ResponseInterface
            {
                return $this->sendRequest($request);
            }
        };
    }

    public function testSynthesizeWritesAudioFile(): void
    {
        $dir = sys_get_temp_dir() . '/tts_test_' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        $client = $this->fakeClient(str_repeat('AUDIOBYTES', 50));
        $provider = new OpenAiTtsProvider('sk-test', ['output_dir' => $dir], $client);

        self::assertSame('openai', $provider->name());
        self::assertSame('gpt-4o-mini-tts', $provider->model());
        self::assertContains('alloy', $provider->supportedVoices());

        $audio = $provider->synthesize('欢迎使用万和水岸智能漫剧');

        self::assertInstanceOf(AudioResponse::class, $audio);
        self::assertTrue($audio->isSuccess());
        self::assertFileExists($audio->firstAudio());
        self::assertGreaterThan(0, filesize($audio->firstAudio()));

        $this->removeDir($dir);
    }

    public function testSynthesizeSendsVoiceAndInstructions(): void
    {
        $client = new class implements \Kode\HttpClient\HttpClientInterface {
            /** @var array<string, string> */
            public array $captured = [];

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->captured['body'] = (string) $request->getBody();
                $this->captured['auth'] = $request->getHeaderLine('Authorization');
                return new Response(200, [], 'data');
            }

            public function sendRequestWithContext(\Psr\Http\Message\RequestInterface $request, mixed $context = null): \Psr\Http\Message\ResponseInterface
            {
                return $this->sendRequest($request);
            }
        };

        $provider = new OpenAiTtsProvider('sk-secret', ['output_dir' => sys_get_temp_dir()], $client);
        $provider->synthesize('你好', ['voice' => 'nova', 'instructions' => '用温柔的语气']);

        $body = json_decode($client->captured['body'], true);
        self::assertSame('nova', $body['voice']);
        self::assertSame('用温柔的语气', $body['instructions']);
        self::assertSame('Bearer sk-secret', $client->captured['auth']);
    }

    public function testEstimateCostApproximates(): void
    {
        $provider = new OpenAiTtsProvider('sk-test', [], $this->fakeClient('x'));
        $cost = $provider->estimateCost(['text' => '中文中文中文中文', 'model' => 'gpt-4o-mini-tts']);

        self::assertGreaterThan(0.0, $cost);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            @unlink($dir . '/' . $item);
        }
        @rmdir($dir);
    }
}
