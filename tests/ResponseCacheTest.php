<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Contract\ResponseInterface;
use Kode\AiAgent\Domain\Model\Response;
use Kode\AiAgent\Token\ResponseCache;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * 响应缓存测试
 */
final class ResponseCacheTest extends TestCase
{
    public function testNullCacheAlwaysMisses(): void
    {
        $cache = new ResponseCache(null);
        $value = $cache->remember('key', fn() => 'value');
        $this->assertSame('value', $value);
    }

    public function testRememberCachesAndRetrieves(): void
    {
        $psrCache = $this->createMock(CacheInterface::class);
        $psrCache->expects($this->once())
            ->method('get')
            ->willReturn(null);
        $psrCache->expects($this->once())
            ->method('set');

        $cache = new ResponseCache($psrCache);

        $callCount = 0;
        $cache->remember('key', function () use (&$callCount) {
            $callCount++;
            return new Response(content: 'reply');
        });

        $this->assertSame(1, $callCount);
    }

    public function testCacheHitSkipsProducer(): void
    {
        $cached = new Response(content: 'cached');

        $psrCache = $this->createMock(CacheInterface::class);
        $psrCache->method('get')->willReturn($cached);

        $cache = new ResponseCache($psrCache);

        $callCount = 0;
        $result = $cache->remember('key', function () use (&$callCount) {
            $callCount++;
            return new Response(content: 'fresh');
        });

        $this->assertSame($cached, $result);
        $this->assertSame(0, $callCount);
    }

    public function testStatistics(): void
    {
        $psrCache = $this->createMock(CacheInterface::class);
        $psrCache->method('get')->willReturn(new Response(
            content: 'cached',
            usage: ['total_tokens' => 100],
        ));

        $cache = new ResponseCache($psrCache);
        $cache->get('key');
        $cache->get('key');
        $cache->get('key');

        $stats = $cache->statistics();
        $this->assertSame(3, $stats['hits']);
        $this->assertSame(300, $stats['saved_tokens']);
    }

    public function testKeyPrefix(): void
    {
        $cache = new ResponseCache(null);
        $this->assertStringStartsWith('ai_agent.response.', $cache->key('foo'));
    }
}
