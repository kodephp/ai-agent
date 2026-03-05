<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;
use Kode\AiAgent\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * AdapterFactory 测试
 */
final class AdapterFactoryTest extends TestCase
{
    public function testSupportedPlatforms(): void
    {
        $supported = AdapterFactory::supported();
        
        $this->assertContains('openai', $supported);
        $this->assertContains('anthropic', $supported);
        $this->assertContains('deepseek', $supported);
        $this->assertContains('qwen', $supported);
        $this->assertContains('baidu', $supported);
        $this->assertContains('tencent', $supported);
        $this->assertContains('xunfei', $supported);
    }

    public function testSupports(): void
    {
        $this->assertTrue(AdapterFactory::supports('openai'));
        $this->assertTrue(AdapterFactory::supports('OPENAI'));
        $this->assertTrue(AdapterFactory::supports('claude'));
        $this->assertTrue(AdapterFactory::supports('qwen'));
        $this->assertTrue(AdapterFactory::supports('baidu'));
        $this->assertTrue(AdapterFactory::supports('wenxin'));
        $this->assertTrue(AdapterFactory::supports('ernie'));
        $this->assertTrue(AdapterFactory::supports('tencent'));
        $this->assertTrue(AdapterFactory::supports('hunyuan'));
        $this->assertTrue(AdapterFactory::supports('xunfei'));
        $this->assertTrue(AdapterFactory::supports('spark'));
        $this->assertTrue(AdapterFactory::supports('xinghuo'));
        $this->assertFalse(AdapterFactory::supports('unknown'));
    }

    public function testCreateUnsupportedPlatform(): void
    {
        $this->expectException(ConfigurationException::class);
        AdapterFactory::create('unknown_platform', ['api_key' => 'test']);
    }

    public function testRegisterCustomAdapter(): void
    {
        AdapterFactory::register('custom', \Kode\AiAgent\Infrastructure\Adapter\OpenAiAdapter::class);
        $this->assertTrue(AdapterFactory::supports('custom'));
    }

    public function testRegisterInvalidAdapterClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AdapterFactory::register('invalid', 'NonExistentClass');
    }

    public function testRegisterInvalidAdapterInterface(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AdapterFactory::register('invalid', \stdClass::class);
    }
}
