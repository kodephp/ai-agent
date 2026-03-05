<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * MCP 服务器接口
 * 
 * 定义模型上下文协议服务器的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 * 
 * @see https://modelcontextprotocol.io/
 * 
 * @example
 * ```php
 * $server = new MCPServer($config);
 * 
 * // 注册工具
 * $server->registerTool('calculator', '计算器', function ($args) {
 *     return $args['a'] + $args['b'];
 * }, ['a' => 'number', 'b' => 'number']);
 * 
 * // 注册资源
 * $server->registerResource('file:///data/config.json', function () {
 *     return file_get_contents('config.json');
 * });
 * 
 * // 处理请求
 * $response = $server->handle($request);
 * ```
 */
interface MCPServerInterface
{
    /**
     * 注册工具
     *
     * @param string $name 工具名称
     * @param string $description 工具描述
     * @param callable $handler 工具处理函数
     * @param array $parameters 参数定义
     */
    public function registerTool(
        string $name,
        string $description,
        callable $handler,
        array $parameters = []
    ): void;

    /**
     * 注册资源
     *
     * @param string $uri 资源 URI
     * @param callable $provider 资源提供函数
     * @param string $mimeType MIME 类型
     */
    public function registerResource(
        string $uri,
        callable $provider,
        string $mimeType = 'text/plain'
    ): void;

    /**
     * 处理 MCP 请求
     *
     * @param array $request MCP 请求
     * @return array MCP 响应
     */
    public function handle(array $request): array;

    /**
     * 获取服务器信息
     *
     * @return array 服务器信息
     */
    public function info(): array;

    /**
     * 获取已注册的工具列表
     *
     * @return array 工具列表
     */
    public function listTools(): array;

    /**
     * 获取已注册的资源列表
     *
     * @return array 资源列表
     */
    public function listResources(): array;
}
