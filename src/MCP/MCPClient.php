<?php

declare(strict_types=1);

namespace Kode\AiAgent\MCP;

use Kode\AiAgent\Domain\Contract\MCPClientInterface;
use Kode\AiAgent\Exception\{ConfigurationException, ConnectionException, InvalidResponseException};
use Kode\HttpClient\HttpClient;
use Nyholm\Psr7\Request;

final class MCPClient implements MCPClientInterface
{
    private ?string $serverUri = null;
    private bool $connected = false;
    private array $serverInfo = [];
    private int $requestId = 1;

    public function __construct(
        private ?HttpClient $client = null,
        private array $config = [],
        private $transport = null,
    ) {
        $this->config = array_merge([
            'protocol_version' => '2024-11-05',
        ], $config);
    }

    public function connect(string $uri): bool
    {
        if (trim($uri) === '') {
            throw ConfigurationException::invalid('uri', 'MCP 服务地址不能为空');
        }

        $this->serverUri = $uri;
        $this->connected = true;

        $result = $this->request('initialize', [
            'protocolVersion' => $this->config['protocol_version'],
            'clientInfo' => $this->config['client_info'] ?? [
                'name' => 'kode-ai-agent-mcp-client',
                'version' => '1.0.0',
            ],
            'capabilities' => $this->config['capabilities'] ?? new \stdClass(),
        ]);

        if (!isset($result['protocolVersion'])) {
            $this->connected = false;
            throw InvalidResponseException::missingField('protocolVersion');
        }

        $this->serverInfo = $result['serverInfo'] ?? [];

        return true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
        $this->serverUri = null;
        $this->serverInfo = [];
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function serverInfo(): array
    {
        return $this->serverInfo;
    }

    public function listTools(): array
    {
        $result = $this->request('tools/list');
        return $result['tools'] ?? [];
    }

    public function callTool(string $name, array $arguments = []): mixed
    {
        $result = $this->request('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ]);

        if (!isset($result['content'])) {
            return $result;
        }

        return $result['content'][0]['text'] ?? $result['content'];
    }

    public function listResources(): array
    {
        $result = $this->request('resources/list');
        return $result['resources'] ?? [];
    }

    public function getResource(string $uri): string
    {
        $result = $this->request('resources/read', ['uri' => $uri]);
        return (string) ($result['contents'][0]['text'] ?? '');
    }

    public function sendRequest(array $request): array
    {
        if (!$this->connected || $this->serverUri === null) {
            throw ConnectionException::refused('未连接 MCP 服务');
        }

        if (is_callable($this->transport)) {
            $response = ($this->transport)($request);
            if (!is_array($response)) {
                throw InvalidResponseException::invalidFormat('array', gettype($response));
            }
            return $response;
        }

        if ($this->client === null) {
            throw ConfigurationException::missing('mcp_http_client');
        }

        try {
            $httpRequest = new Request(
                'POST',
                $this->serverUri,
                ['Content-Type' => 'application/json'],
                json_encode($request, JSON_UNESCAPED_UNICODE)
            );

            $httpResponse = $this->client->sendRequest($httpRequest);
            $body = $httpResponse->getBody()->getContents();
            $payload = json_decode($body, true);

            if (!is_array($payload)) {
                throw InvalidResponseException::jsonFailed('响应非 JSON 对象', ['body' => substr($body, 0, 200)]);
            }

            return $payload;
        } catch (InvalidResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ConnectionException::refused($this->serverUri, $e);
        }
    }

    private function request(string $method, array $params = []): mixed
    {
        $response = $this->sendRequest([
            'jsonrpc' => '2.0',
            'id' => $this->requestId++,
            'method' => $method,
            'params' => $params,
        ]);

        if (isset($response['error'])) {
            $message = $response['error']['message'] ?? 'MCP 请求失败';
            $code = (int) ($response['error']['code'] ?? -1);
            throw InvalidResponseException::invalidFormat('result', "error({$code}): {$message}");
        }

        if (!array_key_exists('result', $response)) {
            throw InvalidResponseException::missingField('result');
        }

        return $response['result'];
    }
}
