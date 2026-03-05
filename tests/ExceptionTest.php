<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Exception\{AuthenticationException, ConfigurationException, PlatformException, RateLimitException, ToolExecutionException};
use PHPUnit\Framework\TestCase;

/**
 * Exception 测试
 */
final class ExceptionTest extends TestCase
{
    public function testAuthenticationExceptionInvalidApiKey(): void
    {
        $exception = AuthenticationException::invalidApiKey('openai');
        
        $this->assertSame(2001, $exception->errorCode());
        $this->assertStringContainsString('API Key', $exception->getMessage());
        $this->assertSame(['provider' => 'openai'], $exception->context());
    }

    public function testConfigurationExceptionMissing(): void
    {
        $exception = ConfigurationException::missing('api_key');
        
        $this->assertSame(101, $exception->errorCode());
        $this->assertStringContainsString('api_key', $exception->getMessage());
    }

    public function testConfigurationExceptionUnsupportedPlatform(): void
    {
        $exception = ConfigurationException::unsupportedPlatform('unknown');
        
        $this->assertSame(103, $exception->errorCode());
        $this->assertStringContainsString('unknown', $exception->getMessage());
    }

    public function testPlatformExceptionConnectionFailed(): void
    {
        $previous = new \RuntimeException('Network error');
        $exception = PlatformException::connectionFailed('https://api.example.com', $previous);
        
        $this->assertSame(1001, $exception->errorCode());
        $this->assertStringContainsString('api.example.com', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testPlatformExceptionServerError(): void
    {
        $exception = PlatformException::serverError(500, 'Internal Server Error');
        
        $this->assertSame(1005, $exception->errorCode());
        $this->assertStringContainsString('500', $exception->getMessage());
    }

    public function testRateLimitExceptionRequestsPerMinute(): void
    {
        $exception = RateLimitException::requestsPerMinute(60);
        
        $this->assertSame(3001, $exception->errorCode());
        $this->assertStringContainsString('60', $exception->getMessage());
    }

    public function testToolExecutionExceptionNotFound(): void
    {
        $exception = ToolExecutionException::notFound('calculator');
        
        $this->assertSame(4001, $exception->errorCode());
        $this->assertStringContainsString('calculator', $exception->getMessage());
    }

    public function testToolExecutionExceptionExecutionFailed(): void
    {
        $exception = ToolExecutionException::executionFailed('calculator', 'Division by zero');
        
        $this->assertSame(4002, $exception->errorCode());
        $this->assertStringContainsString('calculator', $exception->getMessage());
        $this->assertStringContainsString('Division by zero', $exception->getMessage());
    }

    public function testToolExecutionExceptionInvalidArguments(): void
    {
        $exception = ToolExecutionException::invalidArguments('calculator', ['a' => '必须是数字']);
        
        $this->assertSame(4003, $exception->errorCode());
        $this->assertStringContainsString('calculator', $exception->getMessage());
    }

    public function testToolExecutionExceptionTimeout(): void
    {
        $exception = ToolExecutionException::timeout('calculator', 30);
        
        $this->assertSame(4004, $exception->errorCode());
        $this->assertStringContainsString('30', $exception->getMessage());
    }
}
