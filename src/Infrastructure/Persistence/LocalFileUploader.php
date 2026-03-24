<?php

declare(strict_types=1);

namespace Kode\AiAgent\Infrastructure\Persistence;

use Kode\AiAgent\Domain\Contract\FileUploaderInterface;
use Kode\AiAgent\Domain\ValueObject\MediaFile;
use Kode\AiAgent\Exception\FileUploadException;
use Kode\AiAgent\Exception\InvalidInputException;

/**
 * 本地文件上传器实现
 * 
 * 使用本地文件系统存储上传的媒体文件。
 * 
 * @package Kode\AiAgent\Infrastructure\Persistence
 */
readonly class LocalFileUploader implements FileUploaderInterface
{
    public function __construct(
        private string $uploadDir,
        private string $baseUrl = '',
    ) {
        $this->ensureUploadDir();
    }

    #[\NoDiscard]
    public function upload(string $filePath, string $fileName, string $type): MediaFile
    {
        if (!file_exists($filePath)) {
            throw FileUploadException::uploadFailed('源文件不存在', ['path' => $filePath]);
        }

        if (!is_readable($filePath)) {
            throw FileUploadException::permissionDenied($filePath);
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            throw FileUploadException::uploadFailed('无法获取文件大小', ['path' => $filePath]);
        }

        $mimeType = $this->detectMimeType($filePath);
        $uniqueFileName = $this->generateUniqueFileName($fileName);
        $targetPath = $this->uploadDir . DIRECTORY_SEPARATOR . $uniqueFileName;

        if (!copy($filePath, $targetPath)) {
            throw FileUploadException::uploadFailed('文件复制失败', ['source' => $filePath, 'target' => $targetPath]);
        }

        chmod($targetPath, 0644);

        return new MediaFile(
            name: $fileName,
            path: $targetPath,
            size: $fileSize,
            mimeType: $mimeType,
            type: $type,
        );
    }

    #[\NoDiscard]
    public function uploadFromRequest(array $fileData, string $type): MediaFile
    {
        if (!isset($fileData['tmp_name']) || !isset($fileData['name']) || !isset($fileData['size'])) {
            throw new InvalidInputException('无效的文件上传数据');
        }

        if (!isset($fileData['error']) || $fileData['error'] !== UPLOAD_ERR_OK) {
            $error = $fileData['error'] ?? UPLOAD_ERR_NO_FILE;
            throw $this->handleUploadError($error, $fileData);
        }

        $tmpPath = $fileData['tmp_name'];
        $fileName = $fileData['name'];
        $fileSize = $fileData['size'];

        if (!is_uploaded_file($tmpPath)) {
            throw FileUploadException::uploadFailed('不是有效的上传文件', ['path' => $tmpPath]);
        }

        $mimeType = $fileData['type'] ?? $this->detectMimeType($tmpPath);
        $uniqueFileName = $this->generateUniqueFileName($fileName);
        $targetPath = $this->uploadDir . DIRECTORY_SEPARATOR . $uniqueFileName;

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            throw FileUploadException::uploadFailed('文件移动失败', ['source' => $tmpPath, 'target' => $targetPath]);
        }

        chmod($targetPath, 0644);

        return new MediaFile(
            name: $fileName,
            path: $targetPath,
            size: $fileSize,
            mimeType: $mimeType,
            type: $type,
        );
    }

    public function delete(MediaFile $file): bool
    {
        if (!file_exists($file->path)) {
            return true;
        }

        if (!is_writable(dirname($file->path))) {
            throw FileUploadException::permissionDenied(dirname($file->path));
        }

        return unlink($file->path);
    }

    public function exists(MediaFile $file): bool
    {
        return file_exists($file->path) && is_file($file->path);
    }

    public function getUrl(MediaFile $file): string
    {
        $fileName = basename($file->path);
        return rtrim($this->baseUrl, '/') . '/' . $fileName;
    }

    private function ensureUploadDir(): void
    {
        if (!is_dir($this->uploadDir)) {
            if (!mkdir($this->uploadDir, 0755, true)) {
                throw FileUploadException::uploadFailed('无法创建上传目录', ['dir' => $this->uploadDir]);
            }
        }

        if (!is_writable($this->uploadDir)) {
            throw FileUploadException::permissionDenied($this->uploadDir);
        }
    }

    private function detectMimeType(string $filePath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        return $mimeType ?: 'application/octet-stream';
    }

    private function generateUniqueFileName(string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $timestamp = time();
        $random = bin2hex(random_bytes(8));

        return sprintf('%s_%s_%s.%s', $baseName, $timestamp, $random, $extension);
    }

    private function handleUploadError(int $error, array $fileData): FileUploadException
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => FileUploadException::fileTooLarge(
                (int) ini_get('upload_max_filesize') * 1024 * 1024,
                $fileData['size'] ?? 0
            ),
            UPLOAD_ERR_FORM_SIZE => FileUploadException::fileTooLarge(
                $fileData['size'] ?? 0,
                $fileData['size'] ?? 0
            ),
            UPLOAD_ERR_PARTIAL => FileUploadException::uploadFailed('文件只有部分被上传'),
            UPLOAD_ERR_NO_FILE => FileUploadException::uploadFailed('没有文件被上传'),
            UPLOAD_ERR_NO_TMP_DIR => FileUploadException::uploadFailed('找不到临时文件夹'),
            UPLOAD_ERR_CANT_WRITE => FileUploadException::diskFull(),
            UPLOAD_ERR_EXTENSION => FileUploadException::uploadFailed('PHP扩展停止了文件上传'),
        ];

        return $errors[$error] ?? FileUploadException::uploadFailed('未知上传错误', ['error_code' => $error]);
    }
}
