<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * MCP 客户端接口
 * 
 * 定义模型上下文协议客户端的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 * 
 * @example
 * ```php
 * $client = new MCPClient($config);
 * 
 * // 连接到 MCP 服务器
 * $client->connect('ws://localhost:8080/mcp');
 * 
 * // 获取可用工具
 * $tools = $client->listTools();
 * 
 * // 调用工具
 * $result = $client->callTool('calculator', ['a' => 1, 'b' => 2]);
 * 
 * // 获取资源
 * $content = $client->getResource('file:///data/config.json');
 * ```
 */
interface MCPClientInterface
{
    /**
     * 连接到 MCP 服务器
     *
     * @param string $uri 服务器 URI
     * @return bool 是否连接成功
     */
    public function connect(string $uri): bool;

    /**
     * 断开连接
     */
    public function disconnect(): void;

    /**
     * 检查是否已连接
     *
     * @return bool 是否已连接
     */
    public function isConnected(): bool;

    /**
     * 获取服务器信息
     *
     * @return array 服务器信息
     */
    public function serverInfo(): array;

    /**
     * 获取可用工具列表
     *
     * @return array 工具列表
     */
    public function listTools(): array;

    /**
     * 调用工具
     *
     * @param string $name 工具名称
     * @param array $arguments 工具参数
     * @return mixed 工具执行结果
     */
    public function callTool(string $name, array $arguments = []): mixed;

    /**
     * 获取可用资源列表
     *
     * @return array 资源列表
     */
    public function listResources(): array;

    /**
     * 获取资源内容
     *
     * @param string $uri 资源 URI
     * @return string 资源内容
     */
    public function getResource(string $uri): string;

    /**
     * 发送原始请求
     *
     * @param array $request MCP 请求
     * @return array MCP 响应
     */
    public function sendRequest(array $request): array;
}
