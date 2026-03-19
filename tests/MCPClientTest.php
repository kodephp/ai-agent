<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Exception\InvalidResponseException;
use Kode\AiAgent\MCP\MCPClient;
use Kode\AiAgent\MCP\MCPServer;
use PHPUnit\Framework\TestCase;

final class MCPClientTest extends TestCase
{
    public function testConnectAndListTools(): void
    {
        $server = new MCPServer([
            'name' => 'test-mcp',
            'version' => '1.0.0',
        ]);
        $server->registerTool('sum', '求和', fn (array $args): int => (int) ($args['a'] ?? 0) + (int) ($args['b'] ?? 0), [
            'a' => 'number',
            'b' => 'number',
        ]);

        $client = new MCPClient(
            transport: fn (array $request): array => $server->handle($request)
        );

        $this->assertTrue($client->connect('mcp://local'));
        $this->assertTrue($client->isConnected());

        $tools = $client->listTools();
        $this->assertCount(1, $tools);
        $this->assertSame('sum', $tools[0]['name']);
    }

    public function testCallToolAndReadResource(): void
    {
        $server = new MCPServer();
        $server->registerTool('sum', '求和', fn (array $args): int => (int) ($args['a'] ?? 0) + (int) ($args['b'] ?? 0), [
            'a' => 'number',
            'b' => 'number',
        ]);
        $server->registerResource('file:///config/app.json', fn (): string => '{"app":"ai-agent"}', 'application/json');

        $client = new MCPClient(
            transport: fn (array $request): array => $server->handle($request)
        );
        $client->connect('mcp://local');

        $sum = $client->callTool('sum', ['a' => 3, 'b' => 4]);
        $this->assertSame('7', $sum);

        $resources = $client->listResources();
        $this->assertCount(1, $resources);
        $this->assertSame('file:///config/app.json', $resources[0]['uri']);

        $content = $client->getResource('file:///config/app.json');
        $this->assertSame('{"app":"ai-agent"}', $content);
    }

    public function testConnectThrowsWhenProtocolVersionMissing(): void
    {
        $client = new MCPClient(
            transport: fn (): array => ['jsonrpc' => '2.0', 'id' => 1, 'result' => []]
        );

        $this->expectException(InvalidResponseException::class);
        $client->connect('mcp://local');
    }
}
