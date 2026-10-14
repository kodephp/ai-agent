<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Exception\{AuthenticationException, InvalidInputException, PlatformException, RateLimitException};
use Kode\AiAgent\Infrastructure\Adapter\OpenAiAdapter;
use Kode\HttpClient\HttpClientInterface;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * OpenAiAdapter 图像编辑 / 变体生成测试（注入假 HttpClient，不触网）
 *
 * 覆盖 multipart 构造、端点解析、远程图拉取、输入校验、状态码映射，
 * 以及 generateImage 的 JSON 契约回归。
 */
final class OpenAiImageEditTest extends TestCase
{
    private const API_KEY = 'sk-test-key-0123456789abcdef';

    private const PNG_BYTES = "\x89PNG\r\n\x1a\n" . "fakepng-payload-0123456789abcdef";

    private const SUCCESS_JSON = '{"created":1,"data":[{"url":"https://cdn.example.com/out.png"}]}';

    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $captured = [];

    /** @var list<Response> */
    private array $queue = [];

    protected function setUp(): void
    {
        $this->captured = [];
        $this->queue = [];
    }

    private function client(): HttpClientInterface
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function ($request): Response {
            $this->captured[] = $request;
            $next = array_shift($this->queue);
            if ($next === null) {
                throw new \RuntimeException('测试队列为空：sendRequest 调用次数超出预期');
            }
            return $next;
        });

        return $client;
    }

    private function adapter(array $config = []): OpenAiAdapter
    {
        return new OpenAiAdapter($this->client(), ['api_key' => self::API_KEY] + $config);
    }

    private function dataUri(): string
    {
        return 'data:image/png;base64,' . base64_encode(self::PNG_BYTES);
    }

    public function testEditImageSendsMultipartToEditsEndpoint(): void
    {
        $this->queue[] = new Response(200, [], self::SUCCESS_JSON);

        $response = $this->adapter()->editImage($this->dataUri(), 'make it blue', ['size' => '1024x1024']);

        self::assertCount(1, $this->captured);
        $request = $this->captured[0];

        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/v1/images/edits', (string) $request->getUri());
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));

        $body = (string) $request->getBody();
        self::assertStringContainsString('Content-Disposition: form-data; name="image"', $body);
        self::assertStringContainsString('filename="image.png"', $body);
        self::assertStringContainsString('Content-Type: image/png', $body);
        self::assertStringContainsString('name="prompt"', $body);
        self::assertStringContainsString('make it blue', $body);
        self::assertStringContainsString('name="model"', $body);
        self::assertStringContainsString('dall-e-2', $body);
        self::assertStringContainsString('name="size"', $body);
        self::assertStringContainsString('1024x1024', $body);
        self::assertStringContainsString(self::PNG_BYTES, $body);

        self::assertSame(['https://cdn.example.com/out.png'], $response->images());
        self::assertSame('dall-e-2', $response->model);
    }

    public function testEditImageRespectsExplicitEndpointConfig(): void
    {
        $this->queue[] = new Response(200, [], self::SUCCESS_JSON);

        $adapter = $this->adapter(['images_edit_url' => 'https://proxy.internal/custom-edit']);
        $adapter->editImage($this->dataUri(), 'go');

        self::assertSame('https://proxy.internal/custom-edit', (string) $this->captured[0]->getUri());
    }

    public function testVariationSendsMultipartToVariationsEndpoint(): void
    {
        $this->queue[] = new Response(200, [], self::SUCCESS_JSON);

        $rawBase64 = base64_encode(self::PNG_BYTES);
        $response = $this->adapter()->generateImageVariation($rawBase64, ['n' => 2]);

        self::assertCount(1, $this->captured);
        $request = $this->captured[0];

        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/v1/images/variations', (string) $request->getUri());

        $body = (string) $request->getBody();
        self::assertStringContainsString('name="image"', $body);
        self::assertStringNotContainsString('name="prompt"', $body);
        self::assertStringContainsString('name="n"', $body);
        self::assertStringContainsString("\r\n2\r\n", $body);

        self::assertSame(['https://cdn.example.com/out.png'], $response->images());
    }

    public function testEditFetchesRemoteUrlBeforeUpload(): void
    {
        $this->queue[] = new Response(200, ['Content-Type' => 'image/png'], self::PNG_BYTES);
        $this->queue[] = new Response(200, [], self::SUCCESS_JSON);

        $remote = 'https://cdn.example.com/source.png';
        $this->adapter()->editImage($remote, 'enhance');

        self::assertCount(2, $this->captured);

        $fetch = $this->captured[0];
        self::assertSame('GET', $fetch->getMethod());
        self::assertSame($remote, (string) $fetch->getUri());

        $upload = $this->captured[1];
        self::assertSame('POST', $upload->getMethod());
        self::assertStringEndsWith('/v1/images/edits', (string) $upload->getUri());
        $body = (string) $upload->getBody();
        self::assertStringContainsString('filename="source.png"', $body);
        self::assertStringContainsString(self::PNG_BYTES, $body);
    }

    public function testEditRejectsInvalidImageInput(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->adapter()->editImage('%%not-an-image%%', 'go');
    }

    public function testEditAcceptsLocalFilePath(): void
    {
        $path = sys_get_temp_dir() . '/oi-edit-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($path, self::PNG_BYTES);

        try {
            $this->queue[] = new Response(200, [], self::SUCCESS_JSON);
            $this->adapter()->editImage($path, 'tune colors');

            $body = (string) $this->captured[0]->getBody();
            self::assertStringContainsString('filename="' . basename($path) . '"', $body);
            self::assertStringContainsString(self::PNG_BYTES, $body);
        } finally {
            @unlink($path);
        }
    }

    public function testEditUploadsMaskAsSecondFileField(): void
    {
        $this->queue[] = new Response(200, [], self::SUCCESS_JSON);

        $mask = 'data:image/png;base64,' . base64_encode(self::PNG_BYTES . '-mask');
        $this->adapter()->editImage($this->dataUri(), 'apply mask', ['mask' => $mask]);

        $body = (string) $this->captured[0]->getBody();
        self::assertStringContainsString('name="image"', $body);
        self::assertStringContainsString('name="mask"', $body);
        self::assertStringContainsString(self::PNG_BYTES . '-mask', $body);
    }

    public function testEditRejectsEmptyPrompt(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->adapter()->editImage($this->dataUri(), '   ');
    }

    public function testEditMaps401ToAuthenticationException(): void
    {
        $this->queue[] = new Response(401, [], '{"error":{"message":"invalid key"}}');

        $this->expectException(AuthenticationException::class);
        $this->adapter()->editImage($this->dataUri(), 'go');
    }

    public function testEditMaps429ToRateLimitException(): void
    {
        $this->queue[] = new Response(429, [], '{"error":{"message":"rate limited"}}');

        $this->expectException(RateLimitException::class);
        $this->adapter()->editImage($this->dataUri(), 'go');
    }

    public function testImageEndpoint400ThrowsInsteadOfSilentEmptyResponse(): void
    {
        $this->queue[] = new Response(400, [], '{"error":{"message":"invalid image format"}}');

        try {
            $this->adapter()->editImage($this->dataUri(), 'go');
            self::fail('4xx 应抛出 PlatformException 而非静默返回空图');
        } catch (PlatformException $e) {
            self::assertSame(1008, $e->errorCode());
            self::assertStringContainsString('invalid image format', $e->getMessage());
            self::assertSame(400, $e->context()['status_code'] ?? null);
        }
    }

    public function testGenerateImageKeepsJsonContract(): void
    {
        $this->queue[] = new Response(200, [], self::SUCCESS_JSON);

        $response = $this->adapter()->generateImage('a cat');

        self::assertCount(1, $this->captured);
        $request = $this->captured[0];

        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/v1/images/generations', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $request->getBody(), true);
        self::assertSame('a cat', $payload['prompt']);
        self::assertSame('dall-e-3', $payload['model']);

        self::assertSame(['https://cdn.example.com/out.png'], $response->images());
        self::assertSame('dall-e-3', $response->model);
    }

    public function testGenerateImageMaps429ToRateLimitException(): void
    {
        $this->queue[] = new Response(429, [], '{"error":{"message":"slow down"}}');

        $this->expectException(RateLimitException::class);
        $this->adapter()->generateImage('a cat');
    }
}
