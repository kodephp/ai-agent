<?php

declare(strict_types=1);

namespace Kode\AiAgent\Application\Service;

use Kode\AiAgent\Domain\Contract\MultimodalInterface;
use Kode\AiAgent\Domain\Contract\FileUploaderInterface;
use Kode\AiAgent\Domain\Model\{AvatarResponse, ImageResponse, Progress, VideoResponse};
use Kode\AiAgent\Domain\ValueObject\{MediaFile, MultimodalCapability};
use Kode\AiAgent\Exception\InvalidInputException;
use Kode\AiAgent\Exception\PlatformException;
use Psr\Log\LoggerInterface;

readonly class MultimodalService
{
    private const MAX_TEXT_LENGTH = 10000;

    public function __construct(
        private MultimodalInterface $multimodalAdapter,
        private FileUploaderInterface $fileUploader,
        private ?LoggerInterface $logger = null,
    ) {}

    #[\NoDiscard]
    public function generate(string $prompt, array $options = []): mixed
    {
        $this->validateInput($prompt, '提示词');
        $this->log('info', '开始智能生成', ['prompt' => substr($prompt, 0, 100)]);

        try {
            $response = $this->multimodalAdapter->generate($prompt, $options);
            $this->log('info', '智能生成成功');

            return $response;
        } catch (\Throwable $e) {
            $this->log('error', '智能生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function generateImage(string $prompt, array $options = []): ImageResponse
    {
        $this->validateInput($prompt, '提示词');
        $this->ensureCapability(MultimodalCapability::TEXT_TO_IMAGE);
        $this->log('info', '开始生成图像', ['prompt' => substr($prompt, 0, 100)]);

        try {
            $response = $this->multimodalAdapter->generateImage($prompt, $options);
            $this->log('info', '图像生成成功', ['image_url' => $response->image()]);

            return $response;
        } catch (\Throwable $e) {
            $this->log('error', '图像生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function editImage(string $imagePath, string $prompt, array $options = []): ImageResponse
    {
        $this->validateInput($prompt, '提示词');
        $this->ensureCapability(MultimodalCapability::IMAGE_EDIT);
        $this->log('info', '开始编辑图像', ['file' => $imagePath]);

        $mediaFile = $this->fileUploader->upload($imagePath, basename($imagePath), MediaFile::TYPE_IMAGE);

        try {
            $response = $this->multimodalAdapter->editImage($mediaFile->path, $prompt, $options);
            $this->log('info', '图像编辑成功', ['image_url' => $response->image()]);

            return $response;
        } catch (\Throwable $e) {
            $this->log('error', '图像编辑失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function generateImageVariation(string $imagePath, array $options = []): ImageResponse
    {
        $this->ensureCapability(MultimodalCapability::IMAGE_VARIATION);
        $this->log('info', '开始生成图像变体', ['file' => $imagePath]);

        $mediaFile = $this->fileUploader->upload($imagePath, basename($imagePath), MediaFile::TYPE_IMAGE);

        try {
            $response = $this->multimodalAdapter->generateImageVariation($mediaFile->path, $options);
            $this->log('info', '图像变体生成成功', ['image_url' => $response->image()]);

            return $response;
        } catch (\Throwable $e) {
            $this->log('error', '图像变体生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function generateVideo(string $prompt, array $options = []): VideoResponse
    {
        $this->validateInput($prompt, '提示词');
        $this->ensureCapability(MultimodalCapability::TEXT_TO_VIDEO);
        $this->log('info', '开始生成视频', ['prompt' => substr($prompt, 0, 100)]);

        try {
            $response = $this->multimodalAdapter->generateVideo($prompt, $options);
            $this->log('info', '视频生成成功', ['video_url' => $response->video()]);

            return $response;
        } catch (\Throwable $e) {
            $this->log('error', '视频生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function imageToVideo(string $imagePath, ?string $prompt = null, array $options = []): VideoResponse
    {
        $this->ensureCapability(MultimodalCapability::IMAGE_TO_VIDEO);
        $this->log('info', '开始图像转视频', ['file' => $imagePath]);

        $mediaFile = $this->fileUploader->upload($imagePath, basename($imagePath), MediaFile::TYPE_IMAGE);

        try {
            $response = $this->multimodalAdapter->imageToVideo($mediaFile->path, $prompt, $options);
            $this->log('info', '图像转视频成功', ['video_url' => $response->video()]);

            return $response;
        } catch (\Throwable $e) {
            $this->log('error', '图像转视频失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function generateAvatar(string $text, array $options = []): AvatarResponse
    {
        $this->ensureCapability(MultimodalCapability::AVATAR_GENERATION);
        $this->validateInput($text, '口语文本');
        $this->log('info', '开始生成数字人视频', ['text_length' => strlen($text)]);

        try {
            $response = $this->multimodalAdapter->generateAvatarVideo($text, $options);
            $this->log('info', '数字人视频生成成功', ['video_url' => $response->video()]);

            return $response;
        } catch (\Throwable $e) {
            $this->log('error', '数字人视频生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function generateAvatarWithCustomVideo(
        string $text,
        string $videoPath,
        string $videoFileName,
        array $options = []
    ): AvatarResponse {
        $this->ensureCapability(MultimodalCapability::AVATAR_CUSTOM_VIDEO);
        $this->validateInput($text, '口语文本');
        $this->log('info', '开始上传自定义视频', ['file' => $videoFileName]);

        $mediaFile = $this->fileUploader->upload($videoPath, $videoFileName, MediaFile::TYPE_VIDEO);

        $this->log('info', '视频上传成功', ['path' => $mediaFile->path]);
        $this->log('info', '开始生成数字人视频');

        try {
            $response = $this->multimodalAdapter->generateWithCustomVideo($text, $mediaFile, $options);
            $this->log('info', '数字人视频生成成功', ['video_url' => $response->video()]);

            return $response;
        } catch (\Throwable $e) {
            $this->log('error', '数字人视频生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function generateAvatarWithCustomAudio(
        string $audioPath,
        string $audioFileName,
        array $options = []
    ): AvatarResponse {
        $this->ensureCapability(MultimodalCapability::AVATAR_CUSTOM_AUDIO);
        $this->log('info', '开始上传自定义音频', ['file' => $audioFileName]);

        $mediaFile = $this->fileUploader->upload($audioPath, $audioFileName, MediaFile::TYPE_AUDIO);

        $this->log('info', '音频上传成功', ['path' => $mediaFile->path]);
        $this->log('info', '开始生成数字人视频');

        try {
            $response = $this->multimodalAdapter->generateWithCustomAudio($mediaFile, $options);
            $this->log('info', '数字人视频生成成功', ['video_url' => $response->video()]);

            return $response;
        } catch (\Throwable $e) {
            $this->log('error', '数字人视频生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function generateAvatarFromRequestVideo(string $text, array $fileData, array $options = []): AvatarResponse
    {
        $this->ensureCapability(MultimodalCapability::AVATAR_CUSTOM_VIDEO);
        $this->validateInput($text, '口语文本');
        $this->log('info', '开始处理请求中的视频上传');

        $mediaFile = $this->fileUploader->uploadFromRequest($fileData, MediaFile::TYPE_VIDEO);

        $this->log('info', '视频上传成功', ['path' => $mediaFile->path]);

        try {
            $response = $this->multimodalAdapter->generateWithCustomVideo($text, $mediaFile, $options);
            $this->log('info', '数字人视频生成成功');

            return $response;
        } catch (\Throwable $e) {
            $this->log('error', '数字人视频生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function generateAvatarFromRequestAudio(array $fileData, array $options = []): AvatarResponse
    {
        $this->ensureCapability(MultimodalCapability::AVATAR_CUSTOM_AUDIO);
        $this->log('info', '开始处理请求中的音频上传');

        $mediaFile = $this->fileUploader->uploadFromRequest($fileData, MediaFile::TYPE_AUDIO);

        $this->log('info', '音频上传成功', ['path' => $mediaFile->path]);

        try {
            $response = $this->multimodalAdapter->generateWithCustomAudio($mediaFile, $options);
            $this->log('info', '数字人视频生成成功');

            return $response;
        } catch (\Throwable $e) {
            $this->log('error', '数字人视频生成失败', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function generateAsync(string $text, array $options = []): string
    {
        $this->ensureCapability(MultimodalCapability::ASYNC_GENERATION);
        $this->validateInput($text, '口语文本');
        $this->log('info', '开始异步生成数字人视频');

        return $this->multimodalAdapter->generateAvatarVideoAsync($text, $options);
    }

    #[\NoDiscard]
    public function getProgress(string $taskId): Progress
    {
        $this->ensureCapability(MultimodalCapability::PROGRESS_TRACKING);
        return $this->multimodalAdapter->getProgress($taskId);
    }

    #[\NoDiscard]
    public function listAvatars(array $options = []): array
    {
        $this->ensureCapability(MultimodalCapability::AVATAR_LIST);
        return $this->multimodalAdapter->listAvatars($options);
    }

    #[\NoDiscard]
    public function listVoices(array $options = []): array
    {
        $this->ensureCapability(MultimodalCapability::VOICE_LIST);
        return $this->multimodalAdapter->listVoices($options);
    }

    #[\NoDiscard]
    public function capabilities(): array
    {
        return $this->multimodalAdapter->capabilities();
    }

    #[\NoDiscard]
    public function supports(MultimodalCapability $capability): bool
    {
        return $this->multimodalAdapter->supports($capability);
    }

    #[\NoDiscard]
    public function platformName(): string
    {
        return $this->multimodalAdapter->name();
    }

    public function getDownloadPrompt(AvatarResponse $response): string
    {
        return $this->multimodalAdapter->getDownloadPrompt($response);
    }

    public function getFileUrl(MediaFile $file): string
    {
        return $this->fileUploader->getUrl($file);
    }

    private function validateInput(string $input, string $fieldName = '输入'): void
    {
        if (empty(trim($input))) {
            throw new InvalidInputException("{$fieldName}不能为空");
        }

        if (strlen($input) > self::MAX_TEXT_LENGTH) {
            throw new InvalidInputException("{$fieldName}长度不能超过" . self::MAX_TEXT_LENGTH . "字符");
        }
    }

    private function ensureCapability(MultimodalCapability $capability): void
    {
        if (!$this->supports($capability)) {
            throw new PlatformException(
                "当前平台不支持能力: {$capability->label()}",
                1004,
                ['capability' => $capability->value]
            );
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->$level($message, $context);
        }
    }
}
