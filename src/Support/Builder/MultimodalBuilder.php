<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support\Builder;

use Kode\AiAgent\Application\Service\MultimodalService;
use Kode\AiAgent\Domain\Contract\FileUploaderInterface;
use Kode\AiAgent\Domain\Contract\MultimodalInterface;
use Kode\AiAgent\Infrastructure\Persistence\LocalFileUploader;
use Psr\Log\LoggerInterface;

/**
 * 多模态服务构建器
 *
 * 提供链式调用方式构建多模态服务实例。
 *
 * @package Kode\AiAgent\Support\Builder
 *
 * @example
 * ```php
 * $service = MultimodalBuilder::create()
 *     ->withAdapter($adapter)
 *     ->withFileUploader($uploader)
 *     ->withLogger($logger)
 *     ->build();
 * ```
 */
final class MultimodalBuilder
{
    private ?MultimodalInterface $adapter = null;
    private ?FileUploaderInterface $fileUploader = null;
    private ?LoggerInterface $logger = null;
    private ?string $uploadDir = null;
    private ?string $baseUrl = null;

    public static function create(): self
    {
        return new self();
    }

    public function withAdapter(MultimodalInterface $adapter): self
    {
        $this->adapter = $adapter;
        return $this;
    }

    public function withFileUploader(FileUploaderInterface $fileUploader): self
    {
        $this->fileUploader = $fileUploader;
        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function withUploadDir(string $uploadDir): self
    {
        $this->uploadDir = $uploadDir;
        return $this;
    }

    public function withBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    public function build(): MultimodalService
    {
        if ($this->adapter === null) {
            throw new \InvalidArgumentException('适配器不能为空');
        }

        $fileUploader = $this->fileUploader;

        if ($fileUploader === null) {
            $fileUploader = new LocalFileUploader(
                uploadDir: $this->uploadDir ?? sys_get_temp_dir(),
                baseUrl: $this->baseUrl ?? 'https://localhost'
            );
        }

        return new MultimodalService(
            multimodalAdapter: $this->adapter,
            fileUploader: $fileUploader,
            logger: $this->logger
        );
    }
}
