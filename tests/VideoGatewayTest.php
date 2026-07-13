<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Contract\VideoProviderInterface;
use Kode\AiAgent\Domain\Model\VideoResponse;
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\VideoGateway\Strategy\CapabilityAwareVideoStrategy;
use Kode\AiAgent\VideoGateway\Strategy\CostAwareVideoStrategy;
use Kode\AiAgent\VideoGateway\VideoExpert;
use Kode\AiAgent\VideoGateway\VideoGateway;
use Kode\AiAgent\VideoGateway\VideoPriceTable;
use PHPUnit\Framework\TestCase;

/**
 * 统一视频网关测试（使用内存假供应商，不触网）
 */
final class VideoGatewayTest extends TestCase
{
    private function fakeProvider(
        string $name,
        string $model,
        array $caps,
        ?VideoResponse $response = null,
        bool $fail = false,
    ): VideoProviderInterface {
        return new class($name, $model, $caps, $response, $fail) implements VideoProviderInterface {
            public function __construct(
                private string $n,
                private string $m,
                private array $c,
                private ?VideoResponse $r,
                private bool $f,
            ) {}

            public function name(): string { return $this->n; }
            public function model(): string { return $this->m; }
            public function supportedCapabilities(): array { return $this->c; }

            public function textToVideo(string $prompt, array $options = []): VideoResponse
            {
                if ($this->f) {
                    throw new \RuntimeException('boom');
                }
                return $this->r ?? new VideoResponse(videos: ['https://x/' . $this->m]);
            }

            public function imageToVideo(string $image, ?string $prompt = null, array $options = []): VideoResponse
            {
                if ($this->f) {
                    throw new \RuntimeException('boom');
                }
                return $this->r ?? new VideoResponse(videos: ['https://x/' . $this->m]);
            }

            public function generateAvatar(string $text, array $options = []): VideoResponse
            {
                if ($this->f) {
                    throw new \RuntimeException('boom');
                }
                return $this->r ?? new VideoResponse(videos: ['https://x/' . $this->m]);
            }

            public function getProgress(string $taskId): array
            {
                return ['status' => 'SUCCEEDED', 'video_url' => 'https://x/' . $taskId];
            }

            public function estimateCost(array $options = []): float
            {
                return 0.05;
            }
        };
    }

    private function t2v(): MultimodalCapability
    {
        return MultimodalCapability::TEXT_TO_VIDEO;
    }

    public function testGatewayRoutesToCapableProvider(): void
    {
        $seed = $this->fakeProvider('seedance', 'seedance-2.5-pro', [$this->t2v()]);
        $avatar = $this->fakeProvider('aliyun_avatar', 'aliyun-avatar', [MultimodalCapability::AVATAR_GENERATION]);

        $gateway = new VideoGateway();
        $gateway->addProvider($seed, priority: 10);
        $gateway->addProvider($avatar, priority: 20);

        $video = $gateway->textToVideo('一只猫');
        $this->assertStringContainsString('seedance', $video->firstVideo());
    }

    public function testGatewayFallsBackOnFailure(): void
    {
        $broken = $this->fakeProvider('seedance', 'seedance-2.0-pro', [$this->t2v()], fail: true);
        $ok = $this->fakeProvider('wanxiang', 'wanx2.1-t2v-plus', [$this->t2v()]);

        $gateway = new VideoGateway();
        $gateway->addProvider($broken, priority: 10);
        $gateway->addProvider($ok, priority: 20);

        $video = $gateway->textToVideo('一只猫');
        $this->assertStringContainsString('wanx2.1', $video->firstVideo());

        $stats = $gateway->statistics();
        $this->assertSame(1, $stats['seedance:seedance-2.0-pro']['failed']);
        $this->assertSame(1, $stats['wanxiang:wanx2.1-t2v-plus']['success']);
    }

    public function testCostAwareStrategyPicksCheapest(): void
    {
        $price = new VideoPriceTable();
        $price->set('seedance-2.5-pro', 0.20);
        $price->set('wanx2.1-t2v-turbo', 0.03);

        $seed = $this->fakeProvider('seedance', 'seedance-2.5-pro', [$this->t2v()]);
        $cheap = $this->fakeProvider('wanxiang', 'wanx2.1-t2v-turbo', [$this->t2v()]);

        $gateway = new VideoGateway(strategy: 'cost_aware', priceTable: $price);
        $gateway->addProvider($seed, priority: 10);
        $gateway->addProvider($cheap, priority: 20);

        $video = $gateway->textToVideo('一只猫');
        $this->assertStringContainsString('wanx2.1', $video->firstVideo());
    }

    public function testAvatarRouting(): void
    {
        $avatar = $this->fakeProvider('aliyun_avatar', 'aliyun-avatar', [MultimodalCapability::AVATAR_GENERATION]);

        $gateway = new VideoGateway();
        $gateway->addProvider($avatar);

        $video = $gateway->avatar('大家好');
        $this->assertStringContainsString('aliyun', $video->firstVideo());
    }

    public function testReportAggregates(): void
    {
        $seed = $this->fakeProvider('seedance', 'seedance-2.5-pro', [$this->t2v()]);
        $gateway = new VideoGateway();
        $gateway->addProvider($seed);

        $gateway->textToVideo('一只猫');

        $report = $gateway->report();
        $this->assertCount(1, $report['experts']);
        $this->assertSame(1, $report['totals']['success_count']);
        $this->assertSame(1, $report['totals']['request_count']);
    }

    public function testExpertHealthRecovery(): void
    {
        $e = new VideoExpert(
            provider: $this->fakeProvider('seedance', 'seedance-2.0-pro', [$this->t2v()]),
            unhealthyTtlSec: 0,
        );
        $e->markUnhealthy('x');
        $this->assertFalse($e->isHealthy());
        $e->markHealthy();
        $this->assertTrue($e->isHealthy());
    }
}
