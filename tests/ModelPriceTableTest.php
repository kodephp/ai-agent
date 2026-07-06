<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Moe\ModelPriceTable;
use PHPUnit\Framework\TestCase;

/**
 * 模型价格表测试
 */
final class ModelPriceTableTest extends TestCase
{
    public function testKnownModelPrice(): void
    {
        $table = new ModelPriceTable();
        $this->assertSame(0.0025, $table->promptPrice('gpt-4o'));
        $this->assertSame(0.01, $table->completionPrice('gpt-4o'));
    }

    public function testUnknownModelUsesDefault(): void
    {
        $table = new ModelPriceTable();
        $this->assertGreaterThan(0, $table->promptPrice('unknown-model-xyz'));
        $this->assertGreaterThan(0, $table->completionPrice('unknown-model-xyz'));
    }

    public function testEstimateCost(): void
    {
        $table = new ModelPriceTable();
        // gpt-4o: prompt=0.0025, completion=0.01
        // 1000 prompt tokens + 500 completion tokens = 0.0025*1 + 0.01*0.5 = 0.0025 + 0.005 = 0.0075
        $cost = $table->estimate('gpt-4o', 1000, 500);
        $this->assertEqualsWithDelta(0.0075, $cost, 0.0001);
    }

    public function testSetCustomPrice(): void
    {
        $table = new ModelPriceTable();
        $table->setPrice('my-custom-model', 0.001, 0.002);
        $this->assertSame(0.001, $table->promptPrice('my-custom-model'));
        $this->assertSame(0.002, $table->completionPrice('my-custom-model'));
    }

    public function testHas(): void
    {
        $table = new ModelPriceTable();
        $this->assertTrue($table->has('gpt-4o'));
        $this->assertFalse($table->has('unknown-model-xyz'));
    }

    public function testModels(): void
    {
        $table = new ModelPriceTable();
        $models = $table->models();
        $this->assertContains('gpt-4o', $models);
        $this->assertContains('claude-3-5-sonnet', $models);
        $this->assertContains('deepseek-chat', $models);
        $this->assertContains('qwen-plus', $models);
    }
}
