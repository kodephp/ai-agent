<?php

declare(strict_types=1);

namespace Kode\AiAgent\Security;

/**
 * 提示词注入异常
 *
 * @package Kode\AiAgent\Security
 */
final class PromptInjectionException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        int $code = 4001,
        private readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
