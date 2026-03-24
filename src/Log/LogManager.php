<?php

declare(strict_types=1);

namespace Kode\AiAgent\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Kode\Context\Context;

final class LogManager
{
    private static ?LoggerInterface $defaultLogger = null;
    private static array $loggers = [];
    private static bool $enabled = true;
    private static string $env = 'prod';

    public static function init(array $config = []): void
    {
        self::$enabled = $config['enabled'] ?? true;
        self::$env = $config['env'] ?? 'prod';
        self::$defaultLogger = null;
        self::$loggers = [];
    }

    public static function get(?string $name = null): LoggerInterface
    {
        if (!self::$enabled) {
            return new NullLogger();
        }

        if ($name === null) {
            self::$defaultLogger ??= LoggerFactory::create([
                'channel' => 'ai-agent',
                'env' => self::$env,
                'level' => 'debug',
                'path' => 'var/log/ai-agent.log',
            ]);
            return self::$defaultLogger;
        }

        if (!isset(self::$loggers[$name])) {
            self::$loggers[$name] = LoggerFactory::create([
                'channel' => $name,
                'env' => self::$env,
                'level' => 'debug',
                'path' => "var/log/ai-agent-{$name}.log",
            ]);
        }

        return self::$loggers[$name];
    }

    public static function channel(string $name, array $config = []): LoggerInterface
    {
        if (!self::$enabled) {
            return new NullLogger();
        }

        $key = "{$name}_" . md5(json_encode($config));

        if (!isset(self::$loggers[$key])) {
            self::$loggers[$key] = LoggerFactory::create(array_merge([
                'channel' => $name,
                'env' => self::$env,
            ], $config));
        }

        return self::$loggers[$key];
    }

    public static function emergency(string $message, array $context = []): void
    {
        self::get()->emergency($message, self::sanitizeContext($context));
    }

    public static function alert(string $message, array $context = []): void
    {
        self::get()->alert($message, self::sanitizeContext($context));
    }

    public static function critical(string $message, array $context = []): void
    {
        self::get()->critical($message, self::sanitizeContext($context));
    }

    public static function error(string $message, array $context = []): void
    {
        self::get()->error($message, self::sanitizeContext($context));
    }

    public static function warning(string $message, array $context = []): void
    {
        self::get()->warning($message, self::sanitizeContext($context));
    }

    public static function notice(string $message, array $context = []): void
    {
        self::get()->notice($message, self::sanitizeContext($context));
    }

    public static function info(string $message, array $context = []): void
    {
        self::get()->info($message, self::sanitizeContext($context));
    }

    public static function debug(string $message, array $context = []): void
    {
        self::get()->debug($message, self::sanitizeContext($context));
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        self::get()->log($level, $message, self::sanitizeContext($context));
    }

    public static function withContext(array $context): LoggerInterface
    {
        return new ContextualLogger(self::get(), $context);
    }

    public static function withRequestId(string $requestId): LoggerInterface
    {
        return new ContextualLogger(self::get(), ['request_id' => $requestId]);
    }

    private static function sanitizeContext(array $context): array
    {
        $sensitiveKeys = [
            'api_key', 'apikey', 'key', 'token', 'secret',
            'password', 'passwd', 'credential', 'authorization',
            'access_token', 'refresh_token', 'private_key',
        ];

        foreach ($sensitiveKeys as $key) {
            if (isset($context[$key])) {
                $context[$key] = '***REDACTED***';
            }
        }

        if (isset($context['headers'])) {
            $context['headers'] = self::sanitizeHeaders($context['headers']);
        }

        return $context;
    }

    private static function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'x-auth-token'];

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitiveHeaders, true)) {
                $headers[$key] = '***REDACTED***';
            }
        }

        return $headers;
    }
}