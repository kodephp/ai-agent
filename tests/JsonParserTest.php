<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Exception\InvalidResponseException;
use Kode\AiAgent\Support\JsonParser;
use PHPUnit\Framework\TestCase;

/**
 * JSON 解析器测试
 */
final class JsonParserTest extends TestCase
{
    public function testParseValidJson(): void
    {
        $json = '{"choices":[{"message":{"content":"hello"}}],"usage":{"total_tokens":10}}';
        $data = JsonParser::parseArray($json);

        $this->assertSame('hello', $data['choices'][0]['message']['content']);
        $this->assertSame(10, $data['usage']['total_tokens']);
    }

    public function testThrowsOnInvalidJson(): void
    {
        $this->expectException(InvalidResponseException::class);
        JsonParser::parseArray('{invalid json}');
    }

    public function testThrowsOnNonObjectJson(): void
    {
        $this->expectException(InvalidResponseException::class);
        JsonParser::parseArray('"just a string"');
    }

    public function testIsValidReturnsTrueForValidJson(): void
    {
        $this->assertTrue(JsonParser::isValid('{"a":1}'));
    }

    public function testIsValidReturnsFalseForInvalidJson(): void
    {
        $this->assertFalse(JsonParser::isValid('{invalid}'));
    }
}
