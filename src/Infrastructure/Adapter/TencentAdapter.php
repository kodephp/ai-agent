<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Adapter;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Domain\Model\Response;
use Kode\AiAgent\Domain\ValueObject\ApiKey;
use Kode\AiAgent\Exception\{AuthenticationException, ConfigurationException, PlatformException};
use Kode\HttpClient\HttpClient;
use Nyholm\Psr7\Request;

/**
 * 腾讯云混元平台适配器
 * 
 * 实现腾讯云混元大模型 API 的统一接口封装。
 * 使用 SecretId + SecretKey 签名认证方式。
 * 
 * 腾讯云 API 需要使用 TC3-HMAC-SHA256 签名算法。
 * 
 * @package Kode\AiAgent\Infrastructure\Adapter
 * 
 * @example
 * ```php
 * $adapter = new TencentAdapter($client, [
 *     'secret_id' => 'your-secret-id',
 *     'secret_key' => 'your-secret-key',
 *     'model' => 'hunyuan-lite',
 * ]);
 * ```
 */
final class TencentAdapter implements AdapterInterface
{
    use StreamHelper;

    private const BASE_URL = 'hunyuan.tencentcloudapi.com';
    private const SERVICE = 'hunyuan';
    private const VERSION = '2023-09-01';
    private const REGION = 'ap-guangzhou';
    private const DEFAULT_MODEL = 'hunyuan-lite';
    private const ALGORITHM = 'TC3-HMAC-SHA256';

    private ?ApiKey $apiKey;
    private ?string $secretId;
    private ?string $secretKey;

    public function __construct(
        private HttpClient $client,
        private array $config,
    ) {
        $this->validateConfig();
        $this->apiKey = $this->resolveApiKey();
        $this->secretId = $this->config['secret_id'] ?? $this->apiKey?->appKey();
        $this->secretKey = $this->config['secret_key'] ?? $this->apiKey?->secret();
    }

    #[\NoDiscard]
    public function send(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        $startTime = microtime(true);

        try {
            $response = $this->sendRequest($prompt, $options);
            $duration = microtime(true) - $startTime;

            $content = $response['Choices'][0]['Message']['Content'] ?? '';

            $responseObj = new Response(
                content: $content,
                choices: $response['Choices'] ?? [],
                usage: [
                    'prompt_tokens' => $response['Usage']['PromptTokens'] ?? 0,
                    'completion_tokens' => $response['Usage']['CompletionTokens'] ?? 0,
                    'total_tokens' => $response['Usage']['TotalTokens'] ?? 0,
                ],
            );

            return $responseObj->with([
                'duration' => $duration,
                'model' => $response['Model'] ?? $this->resolveModel($options),
                'requestId' => $response['RequestId'] ?? '',
            ]);
        } catch (PlatformException | AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed('https://' . self::BASE_URL, $e);
        }
    }

    #[\NoDiscard]
    public function stream(PromptInterface $prompt, array $options = []): \Generator
    {
        $body = $this->buildRequestBody($prompt, $options, true);

        try {
            $headers = $this->buildSignedHeaders('ChatCompletions', $body);
            $headers['Content-Type'] = 'application/json';
            $headers['Accept'] = 'text/event-stream';

            $request = new Request(
                'POST',
                'https://' . self::BASE_URL,
                $headers,
                json_encode($body)
            );

            $response = $this->client->sendRequest($request);
            $stream = $response->getBody();

            while (!$stream->eof()) {
                $line = $this->readLine($stream);

                if ($line === '') {
                    continue;
                }

                $data = $this->parseSseLine($line);

                if ($data !== null && isset($data['Choices'][0]['Delta']['Content'])) {
                    yield $data['Choices'][0]['Delta']['Content'];
                }
            }
        } catch (\Throwable $e) {
            throw PlatformException::connectionFailed('https://' . self::BASE_URL, $e);
        }
    }

    public function name(): string
    {
        return 'tencent';
    }

    private function sendRequest(PromptInterface $prompt, array $options): array
    {
        $body = $this->buildRequestBody($prompt, $options);
        $headers = $this->buildSignedHeaders('ChatCompletions', $body);
        $headers['Content-Type'] = 'application/json';

        $request = new Request(
            'POST',
            'https://' . self::BASE_URL,
            $headers,
            json_encode($body)
        );

        $response = $this->client->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 401 || $statusCode === 403) {
            throw AuthenticationException::invalidApiKey('tencent');
        }

        if ($statusCode === 429) {
            throw \Kode\AiAgent\Exception\RateLimitException::requestsPerMinute(0);
        }

        if ($statusCode >= 500) {
            throw PlatformException::serverError($statusCode, $response->getReasonPhrase());
        }

        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw PlatformException::responseParseFailed(
                'JSON 解析失败: ' . json_last_error_msg(),
                ['raw' => substr($content, 0, 200)]
            );
        }

        if (isset($data['Response']['Error'])) {
            $error = $data['Response']['Error'];
            $errorCode = $error['Code'] ?? 'UnknownError';
            $errorMsg = $error['Message'] ?? 'Unknown error';

            if (in_array($errorCode, ['AuthFailure', 'AuthFailure.SecretIdNotFound', 'AuthFailure.SignatureFailure'])) {
                throw AuthenticationException::invalidApiKey('tencent: ' . $errorMsg);
            }

            if ($errorCode === 'RequestLimitExceeded') {
                throw \Kode\AiAgent\Exception\RateLimitException::requestsPerMinute(0);
            }

            throw PlatformException::serverError(500, "{$errorCode}: {$errorMsg}");
        }

        return $data['Response'] ?? $data;
    }

    private function buildSignedHeaders(string $action, array $body): array
    {
        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);

        $payload = json_encode($body);
        $hashedPayload = hash('sha256', $payload);

        $canonicalRequest = implode("\n", [
            'POST',
            '/',
            '',
            'content-type:application/json',
            'host:' . self::BASE_URL,
            '',
            'content-type;host',
            $hashedPayload,
        ]);

        $credentialScope = "{$date}/" . self::SERVICE . "/tc3_request";
        $hashedCanonicalRequest = hash('sha256', $canonicalRequest);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $timestamp,
            $credentialScope,
            $hashedCanonicalRequest,
        ]);

        $secretDate = hash_hmac('sha256', $date, 'TC3' . $this->secretKey, true);
        $secretService = hash_hmac('sha256', self::SERVICE, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=content-type;host, Signature=%s',
            self::ALGORITHM,
            $this->secretId,
            $credentialScope,
            $signature
        );

        return [
            'Authorization' => $authorization,
            'X-TC-Action' => $action,
            'X-TC-Version' => self::VERSION,
            'X-TC-Timestamp' => (string) $timestamp,
            'X-TC-Region' => $this->config['region'] ?? self::REGION,
        ];
    }

    private function buildRequestBody(PromptInterface $prompt, array $options, bool $stream = false): array
    {
        $body = [
            'Model' => $this->resolveModel($options),
            'Messages' => [
                ['Role' => 'user', 'Content' => $prompt->text()],
            ],
        ];

        if (isset($options['temperature'])) {
            $body['Temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens'])) {
            $body['MaxTokens'] = (int) $options['max_tokens'];
        }

        if (isset($options['top_p'])) {
            $body['TopP'] = (float) $options['top_p'];
        }

        if ($stream) {
            $body['Stream'] = true;
        }

        return $body;
    }

    private function validateConfig(): void
    {
        $hasSecretId = !empty($this->config['secret_id']);
        $hasSecretKey = !empty($this->config['secret_key']);
        $hasAppKey = !empty($this->config['app_key']) && !empty($this->config['app_secret']);

        if (!($hasSecretId && $hasSecretKey) && !$hasAppKey) {
            throw ConfigurationException::missing('secret_id + secret_key 或 app_key + app_secret');
        }
    }

    private function resolveApiKey(): ?ApiKey
    {
        if (!empty($this->config['app_key']) && !empty($this->config['app_secret'])) {
            return ApiKey::appSecret(
                $this->config['app_key'],
                $this->config['app_secret']
            );
        }

        if (!empty($this->config['secret_id']) && !empty($this->config['secret_key'])) {
            return ApiKey::appSecret(
                $this->config['secret_id'],
                $this->config['secret_key']
            );
        }

        return null;
    }

    private function resolveModel(array $options): string
    {
        return $options['model'] ?? $this->config['model'] ?? self::DEFAULT_MODEL;
    }
}
