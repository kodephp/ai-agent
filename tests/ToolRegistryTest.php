<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * ToolRegistry 测试
 */
final class ToolRegistryTest extends TestCase
{
    public function testRegister(): void
    {
        $registry = new ToolRegistry();
        
        $registry->register('calculator', '计算器', function (int $a, int $b): int {
            return $a + $b;
        });
        
        $this->assertTrue($registry->has('calculator'));
        $this->assertFalse($registry->has('unknown'));
    }

    public function testExecute(): void
    {
        $registry = new ToolRegistry();
        
        $registry->register('add', '加法', function (int $a, int $b): int {
            return $a + $b;
        });
        
        $result = $registry->execute('add', ['a' => 1, 'b' => 2]);
        $this->assertSame(3, $result);
        
        $result = $registry->execute('add', [1, 2]);
        $this->assertSame(3, $result);
    }

    public function testGet(): void
    {
        $registry = new ToolRegistry();
        
        $registry->register('tool1', '工具1', fn() => null);
        
        $tool = $registry->get('tool1');
        $this->assertNotNull($tool);
        $this->assertSame('tool1', $tool['name']);
        $this->assertSame('工具1', $tool['description']);
        
        $this->assertNull($registry->get('unknown'));
    }

    public function testRemove(): void
    {
        $registry = new ToolRegistry();
        
        $registry->register('tool1', '工具1', fn() => null);
        $this->assertTrue($registry->has('tool1'));
        
        $registry->remove('tool1');
        $this->assertFalse($registry->has('tool1'));
    }

    public function testClear(): void
    {
        $registry = new ToolRegistry();
        
        $registry->register('tool1', '工具1', fn() => null);
        $registry->register('tool2', '工具2', fn() => null);
        
        $this->assertCount(2, $registry->all());
        
        $registry->clear();
        $this->assertEmpty($registry->all());
    }

    public function testToOpenAIFormat(): void
    {
        $registry = new ToolRegistry();
        
        $registry->register('calculator', '计算器', function (int $a, int $b): int {
            return $a + $b;
        });
        
        $format = $registry->toOpenAIFormat();
        
        $this->assertCount(1, $format);
        $this->assertSame('function', $format[0]['type']);
        $this->assertSame('calculator', $format[0]['function']['name']);
        $this->assertSame('计算器', $format[0]['function']['description']);
    }

    public function testExecuteWithInvalidTool(): void
    {
        $registry = new ToolRegistry();
        
        $this->expectException(\InvalidArgumentException::class);
        $registry->execute('unknown', []);
    }
}
