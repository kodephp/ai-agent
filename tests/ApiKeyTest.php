<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\ValueObject\ApiKey;
use PHPUnit\Framework\TestCase;

/**
 * ApiKey 测试
 */
final class ApiKeyTest extends TestCase
{
    public function testFromString(): void
    {
        $key = ApiKey::fromString('sk-1234567890abcdefghijklmnop');
        
        $this->assertSame('sk-1234567890abcdefghijklmnop', $key->value());
        $this->assertSame('sk-1...mnop', $key->masked());
        $this->assertTrue($key->isValid());
    }

    public function testFromInvalidString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiKey::fromString('short');
    }

    public function testDualKey(): void
    {
        $key = ApiKey::dual('sk-primary-key-12345', 'sk-secondary-key-12345');
        
        $this->assertSame('sk-primary-key-12345', $key->current());
        $this->assertSame('failover', $key->strategy());
        $this->assertTrue($key->isRotating());
        $this->assertSame(2, $key->count());
    }

    public function testRotatingKey(): void
    {
        $key = ApiKey::rotating([
            'sk-key-one-12345678',
            'sk-key-two-12345678',
            'sk-key-three-1234567',
        ], 'round_robin');
        
        $this->assertSame('round_robin', $key->strategy());
        $this->assertSame(3, $key->count());
        $this->assertTrue($key->isRotating());
    }

    public function testRotate(): void
    {
        $key = ApiKey::rotating([
            'sk-key-one-12345678',
            'sk-key-two-12345678',
        ], 'round_robin');
        
        $first = $key->current();
        $rotated = $key->rotate();
        $second = $rotated->current();
        
        $this->assertNotSame($first, $second);
    }

    public function testFailover(): void
    {
        $key = ApiKey::dual('sk-primary-12345678', 'sk-secondary-12345678');
        
        $first = $key->current();
        $failed = $key->failover();
        $second = $failed->current();
        
        $this->assertNotSame($first, $second);
    }

    public function testMaskedAll(): void
    {
        $key = ApiKey::rotating([
            'sk-key-one-12345678',
            'sk-key-two-12345678',
        ], 'round_robin');
        
        $masked = $key->maskedAll();
        
        $this->assertCount(2, $masked);
        $this->assertStringContainsString('...', $masked[0]);
        $this->assertStringContainsString('...', $masked[1]);
    }

    public function testFromArray(): void
    {
        $key = ApiKey::fromArray([
            'api_key' => 'sk-test-key-123456789012',
        ]);
        
        $this->assertFalse($key->isRotating());
        $this->assertSame(1, $key->count());
    }

    public function testFromArrayWithKeys(): void
    {
        $key = ApiKey::fromArray([
            'keys' => [
                'sk-key-one-12345678',
                'sk-key-two-12345678',
            ],
            'strategy' => 'random',
        ]);
        
        $this->assertTrue($key->isRotating());
        $this->assertSame('random', $key->strategy());
    }

    public function testIsEmpty(): void
    {
        $key = ApiKey::fromString('sk-test-key-123456789012');
        
        $this->assertFalse($key->isEmpty());
    }

    public function testToString(): void
    {
        $key = ApiKey::fromString('sk-test-key-123456789012');
        
        $this->assertSame($key->masked(), (string) $key);
    }

    public function testNext(): void
    {
        $key = ApiKey::rotating([
            'sk-key-one-12345678',
            'sk-key-two-12345678',
        ], 'round_robin');
        
        $current = $key->current();
        $next = $key->next();
        
        $this->assertNotSame($current, $next);
    }

    public function testRandomStrategy(): void
    {
        $key = ApiKey::rotating([
            'sk-key-one-12345678',
            'sk-key-two-12345678',
            'sk-key-three-1234567',
        ], 'random');
        
        $current = $key->current();
        $this->assertTrue($key->isValid());
    }

    public function testValue(): void
    {
        $key = ApiKey::fromString('sk-test-key-123456789012');
        
        $this->assertSame('sk-test-key-123456789012', $key->value());
    }

    public function testCount(): void
    {
        $key = ApiKey::rotating([
            'sk-key-one-12345678',
            'sk-key-two-12345678',
            'sk-key-three-1234567',
        ], 'round_robin');
        
        $this->assertSame(3, $key->count());
    }

    public function testAppSecretMode(): void
    {
        $key = ApiKey::appSecret('app-key-test-123', 'app-secret-test-12345678');
        
        $this->assertSame('app-key-test-123', $key->appKey());
        $this->assertSame('app-secret-test-12345678', $key->secret());
        $this->assertTrue($key->isAppSecretMode());
        $this->assertSame('app-key-test-123', $key->current());
        $this->assertTrue($key->isValid());
    }

    public function testAppSecretWithExtra(): void
    {
        $key = ApiKey::appSecret('app-key-test', 'app-secret-test-12345678', [
            'region' => 'cn-hangzhou',
            'account_id' => '123456',
        ]);
        
        $this->assertSame('cn-hangzhou', $key->extra('region'));
        $this->assertSame('123456', $key->extra('account_id'));
        $this->assertIsArray($key->extra());
    }

    public function testAppSecretInvalidAppKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiKey::appSecret('short', 'app-secret-test-12345678');
    }

    public function testAppSecretInvalidAppSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiKey::appSecret('app-key-test-123', 'short');
    }

    public function testSign(): void
    {
        $key = ApiKey::appSecret('app-key-test', 'app-secret-test-12345678');
        
        $signature = $key->sign('POST', '/v1/chat', ['query' => 'hello']);
        
        $this->assertIsString($signature);
        $this->assertSame(64, strlen($signature)); // sha256 hex
    }

    public function testSignWithoutAppSecret(): void
    {
        $key = ApiKey::fromString('sk-test-key-123456789012');
        
        $this->expectException(\RuntimeException::class);
        $key->sign('POST', '/v1/chat');
    }

    public function testSignedHeaders(): void
    {
        $key = ApiKey::appSecret('app-key-test', 'app-secret-test-12345678');
        
        $headers = $key->signedHeaders('POST', '/v1/chat', ['query' => 'hello']);
        
        $this->assertArrayHasKey('X-App-Key', $headers);
        $this->assertArrayHasKey('X-Timestamp', $headers);
        $this->assertArrayHasKey('X-Nonce', $headers);
        $this->assertArrayHasKey('X-Signature', $headers);
        $this->assertSame('app-key-test', $headers['X-App-Key']);
    }

    public function testSignedHeadersWithoutAppSecret(): void
    {
        $key = ApiKey::fromString('sk-test-key-123456789012');
        
        $headers = $key->signedHeaders('POST', '/v1/chat');
        
        $this->assertEmpty($headers);
    }

    public function testMaskedCredentials(): void
    {
        $key = ApiKey::appSecret('app-key-test-123', 'app-secret-test-12345678');
        
        $masked = $key->maskedCredentials();
        
        $this->assertArrayHasKey('app_key', $masked);
        $this->assertArrayHasKey('app_secret', $masked);
        $this->assertStringContainsString('...', $masked['app_key']);
        $this->assertStringContainsString('...', $masked['app_secret']);
    }

    public function testFromArrayAppSecret(): void
    {
        $key = ApiKey::fromArray([
            'app_key' => 'app-key-test-123',
            'app_secret' => 'app-secret-test-12345678',
            'extra' => ['region' => 'cn-shanghai'],
        ]);
        
        $this->assertTrue($key->isAppSecretMode());
        $this->assertSame('app-key-test-123', $key->appKey());
        $this->assertSame('cn-shanghai', $key->extra('region'));
    }
}
