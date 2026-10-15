<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Contract\{PromptInterface, AvatarInterface};
use Kode\AiAgent\Domain\Model\Progress;
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\Exception\{ConfigurationException, PlatformException};
use Kode\AiAgent\Infrastructure\Adapter\{AdapterFactory, AliyunAvatarAdapter, PlatformMultimodalAdapter};
use Kode\AiAgent\Support\Builder\MultimodalBuilder;
use Kode\HttpClient\HttpClientInterface;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * 阿里数字人适配器测试（注入假 HttpClient，不触网）。
 *
 * 补齐多模态头像链路：此前 AvatarInterface 只有桥接层，没有任何具体平台实现，
 * withPlatform 也注册不到数字人平台。这里验证「提交 + 远端轮询」的同步/异步映射、
 * 对话拒绝、基类默认能力与一步接入。
 */
final class AliyunAvatarAdapterTest extends TestCase
{
    private const API_KEY = 'sk-test-key-0123456789abcdef';

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

    private function adapter(array $config = []): AliyunAvatarAdapter
    {
        return new AliyunAvatarAdapter($this->client(), ['api_key' => self::API_KEY] + $config);
    }

    public function test_generateAvatarVideoSubmitsAndPollsToAvatarResponse(): void
    {
        $this->queue[] = new Response(200, [], '{"task_id":"task-1"}');
        $this->queue[] = new Response(200, [], '{"status":"SUCCEEDED","video_url":"https://cdn.example.com/a.mp4","video_duration":12.5}');

        $response = $this->adapter()->generateAvatarVideo('大家好', [
            'avatar_id' => 'host-f',
            'voice_id' => 'voice-1',
        ]);

        self::assertCount(2, $this->captured);
        $submit = $this->captured[0];
        self::assertSame('POST', $submit->getMethod());
        self::assertStringEndsWith('avatar/generate', (string) $submit->getUri());
        $body = json_decode((string) $submit->getBody(), true);
        self::assertSame('host-f', $body['avatar_id']);
        self::assertSame('voice-1', $body['voice_id']);
        self::assertSame('大家好', $body['text']);

        $poll = $this->captured[1];
        self::assertSame('GET', $poll->getMethod());
        self::assertStringEndsWith('avatar/tasks/task-1', (string) $poll->getUri());

        self::assertSame('https://cdn.example.com/a.mp4', $response->video());
        self::assertSame('host-f', $response->avatarId());
        self::assertSame('voice-1', $response->voiceId());
        self::assertSame('大家好', $response->text);
        self::assertSame(12.5, $response->videoDuration);
        self::assertSame('task-1', $response->requestId);
        self::assertSame('aliyun-avatar', $response->model);
    }

    public function test_asyncSubmitReturnsTaskIdAndProgressGoesRemote(): void
    {
        $this->queue[] = new Response(200, [], '{"task_id":"task-2"}');

        $taskId = $this->adapter()->generateAvatarVideoAsync('异步口播');
        self::assertSame('task-2', $taskId);
        self::assertCount(1, $this->captured, '异步只提交，不本地轮询');

        // 远端仍在跑
        $this->queue[] = new Response(200, [], '{"status":"RUNNING","progress":40}');
        $progress = $this->adapter()->getProgress($taskId);
        self::assertSame(Progress::STATUS_PROCESSING, $progress->status);
        self::assertSame(40, $progress->progress);

        // 远端完成
        $this->queue[] = new Response(200, [], '{"status":"SUCCEEDED","progress":100}');
        $progress = $this->adapter()->getProgress($taskId);
        self::assertSame(Progress::STATUS_COMPLETED, $progress->status);
        self::assertTrue($progress->isCompleted());

        // 远端失败
        $this->queue[] = new Response(200, [], '{"status":"FAILED","message":"素材审核未通过"}');
        $progress = $this->adapter()->getProgress($taskId);
        self::assertSame(Progress::STATUS_FAILED, $progress->status);
        self::assertSame('素材审核未通过', $progress->message);
    }

    public function test_unknownTaskProgressReportsFailed(): void
    {
        $this->queue[] = new Response(200, [], '{}');

        $progress = $this->adapter()->getProgress('no-such');

        self::assertSame(Progress::STATUS_FAILED, $progress->status);
        self::assertSame('任务不存在', $progress->message);
    }

    public function test_chatIsRejectedWithClearError(): void
    {
        $adapter = $this->adapter();
        $prompt = $this->createMock(PromptInterface::class);

        try {
            $adapter->send($prompt);
            self::fail('数字人平台不支持对话，应抛出清晰异常');
        } catch (PlatformException $e) {
            self::assertSame(1004, $e->errorCode());
            self::assertStringContainsString('不支持对话', $e->getMessage());
        }
    }

    public function test_baseClassDefaultsCoverListsAndCustomRejection(): void
    {
        $adapter = $this->adapter();

        self::assertNotSame([], $adapter->listAvatars());
        self::assertNotSame([], $adapter->listVoices());
        self::assertSame('default-female', $adapter->getAvatar('default-female')['id']);

        try {
            $adapter->generateWithCustomVideo(
                'hi',
                new \Kode\AiAgent\Domain\ValueObject\MediaFile('x.mp4', '/tmp/x.mp4', 1024, 'video/mp4', 'video'),
            );
            self::fail('未提供自定义视频能力应抛 1004');
        } catch (PlatformException $e) {
            self::assertSame(1004, $e->errorCode());
        }
    }

    public function test_emptyApiKeyIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new AliyunAvatarAdapter($this->client(), []);
    }

    public function test_fromAdapterInfersAvatarCapabilities(): void
    {
        $bridge = PlatformMultimodalAdapter::fromAdapter($this->adapter(), 'aliyun-avatar');

        self::assertSame('aliyun-avatar', $bridge->name());
        self::assertTrue($bridge->supports(MultimodalCapability::AVATAR_GENERATION));
        self::assertTrue($bridge->supports(MultimodalCapability::ASYNC_GENERATION));
        self::assertTrue($bridge->supports(MultimodalCapability::PROGRESS_TRACKING));
        self::assertTrue($bridge->supports(MultimodalCapability::AVATAR_LIST));
        self::assertFalse($bridge->supports(MultimodalCapability::TEXT_TO_IMAGE), '无图像委托');
        self::assertFalse($bridge->supports(MultimodalCapability::TEXT_TO_VIDEO), '无视频委托');
        self::assertInstanceOf(AvatarInterface::class, $bridge);
    }

    public function test_factoryAndBuilderOneStepIntegration(): void
    {
        $adapter = AdapterFactory::create('aliyun-avatar', ['api_key' => self::API_KEY]);
        self::assertInstanceOf(AliyunAvatarAdapter::class, $adapter);

        $service = MultimodalBuilder::create()
            ->withPlatform('aliyun-avatar', ['api_key' => self::API_KEY])
            ->build();

        self::assertTrue($service->supports(MultimodalCapability::AVATAR_GENERATION));
        self::assertFalse($service->supports(MultimodalCapability::TEXT_TO_IMAGE));
    }
}
