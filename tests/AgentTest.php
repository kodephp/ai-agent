<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Agent\Agent;
use Kode\AiAgent\Domain\Contract\{AdapterInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\Response;
use Kode\AiAgent\Exception\InvalidInputException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Agent 测试
 */
final class AgentTest extends TestCase
{
    public function testAgentCreation(): void
    {
        $adapter = $this->createMockAdapter();
        $agent = new Agent($adapter, [
            'system_prompt' => '你是一个有用的助手',
        ]);

        $this->assertInstanceOf(Agent::class, $agent);
        $this->assertSame($adapter, $agent->adapter());
    }

    public function testChat(): void
    {
        $response = new Response(content: '你好！有什么可以帮助你的？');
        
        $adapter = $this->createMockAdapter();
        $adapter->expects($this->once())
            ->method('send')
            ->willReturn($response);

        $agent = new Agent($adapter);
        $result = $agent->chat('你好');

        $this->assertSame('你好！有什么可以帮助你的？', $result->content());
    }

    public function testSystemPrompt(): void
    {
        $adapter = $this->createMockAdapter();
        $agent = new Agent($adapter, [
            'system_prompt' => '你是一个翻译助手',
        ]);

        $messages = $agent->messages();
        $this->assertCount(1, $messages);
    }

    public function testWithSystemPrompt(): void
    {
        $adapter = $this->createMockAdapter();
        $agent = new Agent($adapter);
        
        $agent->withSystemPrompt('新的系统提示词');
        
        $messages = $agent->messages();
        $this->assertCount(1, $messages);
    }

    public function testClearMessages(): void
    {
        $adapter = $this->createMockAdapter();
        $adapter->expects($this->exactly(2))
            ->method('send')
            ->willReturn(new Response(content: '测试响应'));

        $agent = new Agent($adapter, [
            'system_prompt' => '系统提示',
        ]);
        
        $agent->chat('消息1');
        $agent->chat('消息2');
        
        $this->assertCount(5, $agent->messages());
        
        $agent->clearMessages();
        
        $this->assertCount(1, $agent->messages());
    }

    public function testRegisterTool(): void
    {
        $adapter = $this->createMockAdapter();
        $agent = new Agent($adapter);
        
        $agent->registerTool('calculator', '计算器', function (int $a, int $b): int {
            return $a + $b;
        });
        
        $tools = $agent->tools();
        $this->assertTrue($tools->has('calculator'));
        
        $result = $agent->executeTool('calculator', ['a' => 1, 'b' => 2]);
        $this->assertSame(3, $result);
    }

    public function testWithAdapter(): void
    {
        $adapter1 = $this->createMockAdapter();
        $adapter2 = $this->createMockAdapter();
        
        $agent = new Agent($adapter1);
        $this->assertSame($adapter1, $agent->adapter());
        
        $agent->withAdapter($adapter2);
        $this->assertSame($adapter2, $agent->adapter());
    }

    public function testChatThrowsWhenMessageEmpty(): void
    {
        $adapter = $this->createMockAdapter();
        $agent = new Agent($adapter);

        $this->expectException(InvalidInputException::class);
        $agent->chat('   ');
    }

    private function createMockAdapter(): AdapterInterface&MockObject
    {
        return $this->createMock(AdapterInterface::class);
    }
}
