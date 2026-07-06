<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Security\PiiDetector;
use PHPUnit\Framework\TestCase;

/**
 * PII 检测器测试
 */
final class PiiDetectorTest extends TestCase
{
    private PiiDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new PiiDetector();
    }

    public function testDetectPhoneNumber(): void
    {
        $results = $this->detector->detect('我的手机是13800138000');
        $this->assertNotEmpty($results);
        $this->assertSame('phone', $results[0]['type']);
    }

    public function testMaskPhoneNumber(): void
    {
        $masked = $this->detector->mask('我的手机是13800138000');
        $this->assertStringContainsString('138', $masked);
        $this->assertStringContainsString('8000', $masked);
        $this->assertStringNotContainsString('13800138000', $masked);
    }

    public function testDetectEmail(): void
    {
        $results = $this->detector->detect('联系邮箱：test@example.com');
        $this->assertNotEmpty($results);
        $this->assertSame('email', $results[0]['type']);
    }

    public function testMaskEmail(): void
    {
        $masked = $this->detector->mask('联系：john.doe@example.com');
        $this->assertStringContainsString('@example.com', $masked);
        $this->assertStringNotContainsString('john.doe', $masked);
    }

    public function testDetectIdCard(): void
    {
        $results = $this->detector->detect('身份证：110101199001011234');
        $this->assertNotEmpty($results);
        $this->assertSame('id_card', $results[0]['type']);
    }

    public function testMaskIdCard(): void
    {
        $masked = $this->detector->mask('身份证：110101199001011234');
        $this->assertStringContainsString('1101', $masked);
        $this->assertStringContainsString('1234', $masked);
        $this->assertStringNotContainsString('110101199001011234', $masked);
    }

    public function testDetectIpAddress(): void
    {
        $results = $this->detector->detect('服务器IP是192.168.1.1');
        $this->assertNotEmpty($results);
        $this->assertSame('ip_address', $results[0]['type']);
    }

    public function testHasSensitive(): void
    {
        $this->assertTrue($this->detector->hasSensitive('手机：13800138000'));
        $this->assertFalse($this->detector->hasSensitive('这是一段普通文本'));
    }

    public function testCount(): void
    {
        $count = $this->detector->count('手机13800138000 邮箱test@example.com');
        $this->assertSame(2, $count);
    }
}
