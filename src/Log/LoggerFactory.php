<?php

declare(strict_types=1);

namespace Kode\AiAgent\Log;

use Monolog\{Level, Logger, Handler\StreamHandler, Handler\RotatingFileHandler, Handler\NullHandler, Processor\PsrLogMessageProcessor, Processor\IntrospectionProcessor, Formatter\JsonFormatter};
use Monolog\Processor\UidProcessor;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    private const DEFAULT_CHANNEL = 'ai-agent';
    private const DEFAULT_PATH = 'var/log/ai-agent.log';
    private const MAX_FILES = 30;

    public static function create(string|array $config = []): LoggerInterface
    {
        $channel = $config['channel'] ?? self::DEFAULT_CHANNEL;
        $level = self::parseLevel($config['level'] ?? 'info');
        $path = $config['path'] ?? self::DEFAULT_PATH;
        $env = $config['env'] ?? 'prod';

        $logger = new Logger($channel);

        if ($env === 'test' || ($config['enabled'] ?? true) === false) {
            $logger->pushHandler(new NullHandler());
            return $logger;
        }

        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(new UidProcessor());

        if ($env === 'dev' || $env === 'debug') {
            $logger->pushProcessor(new IntrospectionProcessor(Level::Debug, [
                'Kode\AiAgent',
                'Monolog',
            ]));
        }

        if (is_string($path) && str_starts_with($path, 'php://')) {
            $logger->pushHandler(new StreamHandler($path, $level));
        } else {
            $rotating = new RotatingFileHandler($path, self::MAX_FILES, $level);
            $rotating->setFormatter(new JsonFormatter());
            $logger->pushHandler($rotating);
        }

        return $logger;
    }

    public static function console(string|array $config = []): LoggerInterface
    {
        $level = self::parseLevel($config['level'] ?? 'debug');
        $logger = new Logger('console');

        $output = $config['output'] ?? 'php://stdout';
        $logger->pushHandler(new StreamHandler($output, $level));
        $logger->pushProcessor(new PsrLogMessageProcessor());

        return $logger;
    }

    public static function null(): LoggerInterface
    {
        return new Logger('null', [new NullHandler()]);
    }

    private static function parseLevel(string|int $level): Level
    {
        if (is_int($level)) {
            return Level::fromName(Level::getName($level));
        }

        return match (strtolower($level)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning', 'warn' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }
}