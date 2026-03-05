<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

use Kode\AiAgent\Domain\ValueObject\MediaFile;
use Kode\AiAgent\Exception\FileUploadException;
use Kode\AiAgent\Exception\InvalidInputException;

/**
 * 文件上传器接口
 * 
 * 定义媒体文件（视频、音频）上传的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 */
interface FileUploaderInterface
{
    /**
     * 上传文件
     * 
     * @param string $filePath 本地文件路径
     * @param string $fileName 原始文件名
     * @param string $type 文件类型 (video/audio/image)
     * 
     * @return MediaFile 上传后的媒体文件对象
     * 
     * @throws FileUploadException 当上传失败时
     * @throws InvalidInputException 当输入无效时
     */
    #[\NoDiscard]
    public function upload(string $filePath, string $fileName, string $type): MediaFile;

    /**
     * 从 $_FILES 上传文件
     * 
     * @param array $fileData $_FILES 数组中的单个文件数据
     * @param string $type 文件类型 (video/audio/image)
     * 
     * @return MediaFile 上传后的媒体文件对象
     * 
     * @throws FileUploadException 当上传失败时
     * @throws InvalidInputException 当输入无效时
     */
    #[\NoDiscard]
    public function uploadFromRequest(array $fileData, string $type): MediaFile;

    /**
     * 删除文件
     * 
     * @param MediaFile $file 要删除的媒体文件
     * 
     * @return bool 是否删除成功
     * 
     * @throws FileUploadException 当删除失败时
     */
    public function delete(MediaFile $file): bool;

    /**
     * 检查文件是否存在
     * 
     * @param MediaFile $file 要检查的媒体文件
     * 
     * @return bool 文件是否存在
     */
    public function exists(MediaFile $file): bool;

    /**
     * 获取文件访问 URL
     * 
     * @param MediaFile $file 媒体文件
     * 
     * @return string 文件访问 URL
     */
    public function getUrl(MediaFile $file): string;
}
