<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

/**
 * 管道测试
 */
final class PipelineTest extends TestCase
{
    public function testEmptyPipeline(): void
    {
        $pipeline = new Pipeline();

        $this->assertTrue($pipeline->isEmpty());
        $this->assertSame(0, $pipeline->count());

        $result = $pipeline->process('hello');
        $this->assertSame('hello', $result);
    }

    public function testSingleStage(): void
    {
        $pipeline = new Pipeline();

        $pipeline->pipe(function ($input, $next) {
            return strtoupper($input);
        });

        $result = $pipeline->process('hello');
        $this->assertSame('HELLO', $result);
    }

    public function testMultipleStages(): void
    {
        $pipeline = new Pipeline();

        $pipeline
            ->pipe(function ($input, $next) {
                return $next(strtoupper($input));
            })
            ->pipe(function ($input, $next) {
                return $next($input . '!');
            })
            ->pipe(function ($input, $next) {
                return 'Result: ' . $input;
            });

        $result = $pipeline->process('hello');
        $this->assertSame('Result: HELLO!', $result);
    }

    public function testPipelineReset(): void
    {
        $pipeline = new Pipeline();

        $pipeline->pipe(function ($input, $next) {
            return strtoupper($input);
        });

        $this->assertSame(1, $pipeline->count());

        $pipeline->reset();

        $this->assertSame(0, $pipeline->count());
        $this->assertTrue($pipeline->isEmpty());
    }

    public function testWithDestination(): void
    {
        $pipeline = new Pipeline();

        $pipeline->pipe(function ($input, $next) {
            return $next($input * 2);
        });

        $result = $pipeline->process(5, function ($input) {
            return $input + 10;
        });

        $this->assertSame(20, $result);
    }
}
