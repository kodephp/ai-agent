<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Support\Validator\InputValidator;
use Kode\AiAgent\Exception\InvalidInputException;
use PHPUnit\Framework\TestCase;

/**
 * InputValidator 测试
 */
final class InputValidatorTest extends TestCase
{
    private InputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new InputValidator();
    }

    public function testValidatePrompt(): void
    {
        $result = $this->validator->validatePrompt('你好，世界');
        
        $this->assertSame('你好，世界', $result);
    }

    public function testValidatePromptTrimsWhitespace(): void
    {
        $result = $this->validator->validatePrompt('  你好  ');
        
        $this->assertSame('你好', $result);
    }

    public function testValidatePromptEmptyThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->validator->validatePrompt('');
    }

    public function testValidatePromptTooShortThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->validator->validatePrompt('a', ['min_length' => 2]);
    }

    public function testValidatePromptTooLongThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->validator->validatePrompt(str_repeat('a', 1001), ['max_length' => 1000]);
    }

    public function testValidateMessages(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi!'],
        ];
        
        $result = $this->validator->validateMessages($messages);
        
        $this->assertCount(3, $result);
    }

    public function testValidateMessagesInvalidRoleThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        
        $this->validator->validateMessages([
            ['role' => 'invalid', 'content' => 'test'],
        ]);
    }

    public function testValidateMessagesMissingRoleThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        
        $this->validator->validateMessages([
            ['content' => 'test'],
        ]);
    }

    public function testValidateToolCall(): void
    {
        $result = $this->validator->validateToolCall('calculator', ['a' => 1, 'b' => 2]);
        
        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function testValidateToolCallEmptyNameThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->validator->validateToolCall('', []);
    }

    public function testValidateToolCallInvalidNameThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->validator->validateToolCall('123invalid', []);
    }

    public function testValidateOptions(): void
    {
        $options = [
            'temperature' => 0.7,
            'max_tokens' => 1000,
            'top_p' => 0.9,
        ];
        
        $result = $this->validator->validateOptions($options);
        
        $this->assertSame($options, $result);
    }

    public function testValidateOptionsInvalidTemperatureThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->validator->validateOptions(['temperature' => 3.0]);
    }

    public function testValidateOptionsInvalidMaxTokensThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->validator->validateOptions(['max_tokens' => 0]);
    }

    public function testIsValidApiKey(): void
    {
        $this->assertTrue($this->validator->isValidApiKey('sk-1234567890abcdefghijklmnop'));
        $this->assertFalse($this->validator->isValidApiKey('short'));
        $this->assertFalse($this->validator->isValidApiKey('has space key'));
    }
}
