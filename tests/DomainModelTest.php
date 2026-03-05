<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Model\Message;
use Kode\AiAgent\Domain\Model\Prompt;
use Kode\AiAgent\Domain\Model\Response;
use PHPUnit\Framework\TestCase;

/**
 * Domain Model 测试
 */
final class DomainModelTest extends TestCase
{
    public function testMessageUser(): void
    {
        $message = Message::user('你好');
        
        $this->assertSame('user', $message->role());
        $this->assertSame('你好', $message->content());
    }

    public function testMessageSystem(): void
    {
        $message = Message::system('你是一个有用的助手');
        
        $this->assertSame('system', $message->role());
        $this->assertSame('你是一个有用的助手', $message->content());
    }

    public function testMessageAssistant(): void
    {
        $message = Message::assistant('你好！有什么可以帮助你的？');
        
        $this->assertSame('assistant', $message->role());
        $this->assertSame('你好！有什么可以帮助你的？', $message->content());
    }

    public function testMessageTool(): void
    {
        $message = Message::tool('calculator', ['a' => 1, 'b' => 2], '3', 'call-123');
        
        $this->assertSame('tool', $message->role());
        $this->assertSame('3', $message->content());
        $this->assertSame('calculator', $message->name());
        $this->assertSame('call-123', $message->toolCallId());
        $this->assertSame(['a' => 1, 'b' => 2], $message->toolArguments());
    }

    public function testMessageToArray(): void
    {
        $message = Message::user('测试');
        $array = $message->toArray();
        
        $this->assertArrayHasKey('role', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertSame('user', $array['role']);
        $this->assertSame('测试', $array['content']);
    }

    public function testMessageWith(): void
    {
        $message = Message::user('原始内容');
        $newMessage = $message->with(['content' => '新内容']);
        
        $this->assertSame('原始内容', $message->content());
        $this->assertSame('新内容', $newMessage->content());
    }

    public function testPrompt(): void
    {
        $prompt = new Prompt('这是一个提示词');
        
        $this->assertSame('这是一个提示词', $prompt->text());
        $this->assertSame('这是一个提示词', (string) $prompt);
    }

    public function testPromptToArray(): void
    {
        $prompt = new Prompt('测试提示词');
        $array = $prompt->toArray();
        
        $this->assertArrayHasKey('text', $array);
        $this->assertSame('测试提示词', $array['text']);
    }

    public function testResponse(): void
    {
        $response = new Response(
            content: '响应内容',
            choices: [['message' => ['content' => '响应内容']]],
            usage: ['total_tokens' => 100]
        );
        
        $this->assertSame('响应内容', $response->content());
        $this->assertSame('响应内容', (string) $response);
        $this->assertCount(1, $response->choices());
        $this->assertSame(100, $response->usage()['total_tokens']);
    }

    public function testResponseWith(): void
    {
        $response = new Response('原始内容');
        $newResponse = $response->with(['content' => '新内容']);
        
        $this->assertSame('原始内容', $response->content());
        $this->assertSame('新内容', $newResponse->content());
    }

    public function testResponseIsSuccess(): void
    {
        $response = new Response('成功');
        
        $this->assertTrue($response->isSuccess());
    }

    public function testResponseToArray(): void
    {
        $response = new Response('测试响应');
        $array = $response->toArray();
        
        $this->assertArrayHasKey('code', $array);
        $this->assertArrayHasKey('msg', $array);
        $this->assertArrayHasKey('data', $array);
    }

    public function testResponseToJson(): void
    {
        $response = new Response('测试响应');
        $json = $response->toJson();
        
        $this->assertJson($json);
        $data = json_decode($json, true);
        $this->assertArrayHasKey('code', $data);
    }
}
