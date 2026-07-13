<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\VideoGateway\Provider\AliyunAvatarProvider;
use Kode\AiAgent\VideoGateway\Provider\SeedanceVideoProvider;
use Kode\AiAgent\VideoGateway\Provider\WanxiangVideoProvider;
use PHPUnit\Framework\TestCase;

/**
 * 视频供应商能力 / 成本 / 版本解析测试（不触网）
 */
final class VideoProviderTest extends TestCase
{
    public function testSeedanceResolves25Pro(): void
    {
        $p = new SeedanceVideoProvider('sk-x', ['version' => '2.5', 'tier' => 'pro']);
        $this->assertSame('seedance-2.5-pro', $p->model());
        $this->assertSame('seedance', $p->name());
    }

    public function testSeedanceResolves20Lite(): void
    {
        $p = new SeedanceVideoProvider('sk-x', ['version' => '2.0', 'tier' => 'lite']);
        $this->assertSame('seedance-2.0-lite', $p->model());
    }

    public function testSeedanceExplicitModelWins(): void
    {
        $p = new SeedanceVideoProvider('sk-x', ['model' => 'seedance-2.5-lite']);
        $this->assertSame('seedance-2.5-lite', $p->model());
    }

    public function testSeedanceCapabilities(): void
    {
        $caps = (new SeedanceVideoProvider('sk-x'))->supportedCapabilities();
        $this->assertContains(MultimodalCapability::TEXT_TO_VIDEO, $caps);
        $this->assertContains(MultimodalCapability::IMAGE_TO_VIDEO, $caps);
        $this->assertNotContains(MultimodalCapability::AVATAR_GENERATION, $caps);
    }

    public function testSeedanceEstimateCostScalesWithResolution(): void
    {
        $p = new SeedanceVideoProvider('sk-x', ['version' => '2.5', 'tier' => 'pro']);
        $this->assertGreaterThan(
            $p->estimateCost(['resolution' => '720p', 'duration' => 5]),
            $p->estimateCost(['resolution' => '1080p', 'duration' => 10])
        );
    }

    public function testWanxiangCapabilitiesAndCost(): void
    {
        $p = new WanxiangVideoProvider('sk-x');
        $this->assertSame('wanxiang', $p->name());
        $this->assertSame('wanx2.1-t2v-plus', $p->model());
        $caps = $p->supportedCapabilities();
        $this->assertContains(MultimodalCapability::TEXT_TO_VIDEO, $caps);
        $this->assertContains(MultimodalCapability::IMAGE_TO_VIDEO, $caps);
        $this->assertSame(0.07, $p->estimateCost());
    }

    public function testAliyunAvatarCapabilitiesAndCost(): void
    {
        $p = new AliyunAvatarProvider('sk-x');
        $this->assertSame('aliyun_avatar', $p->name());
        $this->assertSame('aliyun-avatar', $p->model());
        $caps = $p->supportedCapabilities();
        $this->assertContains(MultimodalCapability::AVATAR_GENERATION, $caps);
        $this->assertSame(0.20, $p->estimateCost());
    }

    public function testAliyunAvatarRejectsTextToVideo(): void
    {
        $this->expectException(\Kode\AiAgent\Exception\PlatformException::class);
        (new AliyunAvatarProvider('sk-x'))->textToVideo('hi');
    }
}
