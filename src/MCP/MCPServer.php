<?php

declare(strict_types=1);

namespace Kode\AiAgent\MCP;

use Kode\AiAgent\Domain\Contract\MCPServerInterface;

/**
 * MCP 服务器实现
 * 
 * 实现模型上下文协议 (Model Context Protocol) 服务器。
 * 支持工具注册、资源注册和请求处理。
 * 
 * @package Kode\AiAgent\MCP
 * 
 * @see https://modelcontextprotocol.io/
 * 
 * @example
 * ```php
 * $server = new MCPServer([
 *     'name' => 'my-mcp-server',
 *     'version' => '1.0.0',
 * ]);
 * 
 * $server->registerTool('echo', '回显消息', function ($args) {
 *     return $args['message'] ?? '';
 * }, ['message' => 'string']);
 * 
 * // 处理 JSON-RPC 请求
 * $response = $server->handle([
 *     'jsonrpc' => '2.0',
 *     'method' => 'tools/call',
 *     'params' => ['name' => 'echo', 'arguments' => ['message' => 'Hello']],
 *     'id' => 1,
 * ]);
 * ```
 */
final class MCPServer implements MCPServerInterface
{
    private const PROTOCOL_VERSION = '2024-11-05';

    private array $tools = [];
    private array $resources = [];

    public function __construct(
        private array $config = [],
    ) {
        $this->config = array_merge([
            'name' => 'kode-ai-agent-mcp',
            'version' => '1.0.0',
        ], $config);
    }

    public function registerTool(
        string $name,
        string $description,
        callable $handler,
        array $parameters = []
    ): void {
        $this->tools[$name] = [
            'name' => $name,
            'description' => $description,
            'handler' => $handler,
            'inputSchema' => $this->buildInputSchema($parameters),
        ];
    }

    public function registerResource(
        string $uri,
        callable $provider,
        string $mimeType = 'text/plain'
    ): void {
        $this->resources[$uri] = [
            'uri' => $uri,
            'provider' => $provider,
            'mimeType' => $mimeType,
        ];
    }

    public function handle(array $request): array
    {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($params),
                'resources/list' => $this->handleResourcesList(),
                'resources/read' => $this->handleResourcesRead($params),
                'ping' => ['status' => 'ok'],
                default => throw new \InvalidArgumentException("未知方法: {$method}"),
            };

            return $this->successResponse($result, $id);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getCode() ?: -1, $e->getMessage(), $id);
        }
    }

    public function info(): array
    {
        return [
            'name' => $this->config['name'],
            'version' => $this->config['version'],
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => !empty($this->tools),
                'resources' => !empty($this->resources),
            ],
        ];
    }

    public function listTools(): array
    {
        return array_map(function ($tool) {
            return [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }, array_values($this->tools));
    }

    public function listResources(): array
    {
        return array_map(function ($resource) {
            return [
                'uri' => $resource['uri'],
                'mimeType' => $resource['mimeType'],
            ];
        }, array_values($this->resources));
    }

    private function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => true],
                'resources' => ['subscribe' => false, 'listChanged' => true],
            ],
            'serverInfo' => [
                'name' => $this->config['name'],
                'version' => $this->config['version'],
            ],
        ];
    }

    private function handleToolsList(): array
    {
        return [
            'tools' => $this->listTools(),
        ];
    }

    private function handleToolsCall(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("工具不存在: {$name}");
        }

        $handler = $this->tools[$name]['handler'];
        $result = $handler($arguments);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => is_string($result) ? $result : json_encode($result),
                ],
            ],
        ];
    }

    private function handleResourcesList(): array
    {
        return [
            'resources' => $this->listResources(),
        ];
    }

    private function handleResourcesRead(array $params): array
    {
        $uri = $params['uri'] ?? '';

        if (!isset($this->resources[$uri])) {
            throw new \InvalidArgumentException("资源不存在: {$uri}");
        }

        $provider = $this->resources[$uri]['provider'];
        $content = $provider();
        $mimeType = $this->resources[$uri]['mimeType'];

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => $mimeType,
                    'text' => $content,
                ],
            ],
        ];
    }

    private function buildInputSchema(array $parameters): array
    {
        if (empty($parameters)) {
            return [
                'type' => 'object',
                'properties' => new \stdClass(),
            ];
        }

        $properties = [];
        $required = [];

        foreach ($parameters as $name => $type) {
            $properties[$name] = [
                'type' => $type === 'number' ? 'number' : 'string',
            ];
            $required[] = $name;
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    private function successResponse(mixed $result, mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id,
        ];
    }

    private function errorResponse(int $code, string $message, mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => $id,
        ];
    }
}
