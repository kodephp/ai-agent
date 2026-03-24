<?php

declare(strict_types=1);

namespace Kode\AiAgent\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

final class ContextualLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private LoggerInterface $logger,
        private array $context = [],
    ) {}

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $mergedContext = array_merge($this->context, $context);
        $this->logger->log($level, $message, $mergedContext);
    }

    public function withContext(array $context): self
    {
        return new self($this->logger, array_merge($this->context, $context));
    }
}