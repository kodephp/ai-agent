<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support\Facade;

use Kode\AiAgent\Application\Service\MultimodalService;
use Kode\AiAgent\Domain\Contract\{FileUploaderInterface, MultimodalInterface};
use Kode\AiAgent\Domain\Model\{AvatarResponse, ImageResponse, Progress, VideoResponse};
use Kode\AiAgent\Domain\ValueObject\{MediaFile, MultimodalCapability};
use Kode\AiAgent\Exception\ConfigurationException;
use Kode\AiAgent\Infrastructure\Persistence\LocalFileUploader;
use Kode\Context\Context as KodeContext;
use Kode\Facade\Facade;
use Psr\Log\LoggerInterface;

/**
 * 多模态门面类
 * 
 * 继承 kode/facade 提供简洁的静态调用接口。
 * 
 * @package Kode\AiAgent\Support\Facade
 * 
 * @method static mixed generate(string $prompt, array $options = [])
 * @method static ImageResponse generateImage(string $prompt, array $options = [])
 * @method static ImageResponse editImage(string $imagePath, string $prompt, array $options = [])
 * @method static ImageResponse generateImageVariation(string $imagePath, array $options = [])
 * @method static VideoResponse generateVideo(string $prompt, array $options = [])
 * @method static VideoResponse imageToVideo(string $imagePath, ?string $prompt = null, array $options = [])
 * @method static AvatarResponse generateAvatar(string $text, array $options = [])
 * @method static AvatarResponse generateAvatarWithCustomVideo(string $text, string $videoPath, string $videoFileName, array $options = [])
 * @method static AvatarResponse generateAvatarWithCustomAudio(string $audioPath, string $audioFileName, array $options = [])
 * @method static AvatarResponse generateAvatarFromRequestVideo(string $text, array $fileData, array $options = [])
 * @method static AvatarResponse generateAvatarFromRequestAudio(array $fileData, array $options = [])
 * @method static string generateAsync(string $text, array $options = [])
 * @method static Progress getProgress(string $taskId)
 * @method static array listAvatars(array $options = [])
 * @method static array listVoices(array $options = [])
 * @method static array capabilities()
 * @method static bool supports(MultimodalCapability $capability)
 * @method static string platformName()
 * @method static string getDownloadPrompt(AvatarResponse $response)
 * @method static string getFileUrl(MediaFile $file)
 * @method static MultimodalService using(string $platform)
 * @method static void setDefaultService(MultimodalService $service)
 * @method static void register(string $name, MultimodalService $service)
 * @method static MultimodalService service()
 * @method static void reset()
 */
final class Multimodal extends Facade
{
    private const CONTEXT_DEFAULT_SERVICE_KEY = 'ai_agent.multimodal.default_service';
    private const CONTEXT_SERVICES_KEY = 'ai_agent.multimodal.services';
    private const CONTEXT_FILE_UPLOADER_KEY = 'ai_agent.multimodal.file_uploader';
    private const CONTEXT_LOGGER_KEY = 'ai_agent.multimodal.logger';

    private static ?MultimodalService $defaultService = null;
    private static array $services = [];
    private static ?FileUploaderInterface $defaultFileUploader = null;
    private static ?LoggerInterface $defaultLogger = null;

    protected static function id(): string
    {
        return 'multimodal';
    }

    public static function getInstance(): object
    {
        return new self();
    }

    public static function setFileUploader(FileUploaderInterface $uploader): void
    {
        self::$defaultFileUploader = $uploader;
        KodeContext::set(self::CONTEXT_FILE_UPLOADER_KEY, $uploader);
    }

    public static function setLogger(LoggerInterface $logger): void
    {
        self::$defaultLogger = $logger;
        KodeContext::set(self::CONTEXT_LOGGER_KEY, $logger);
    }

    #[\NoDiscard]
    public function generate(string $prompt, array $options = []): mixed
    {
        return self::service()->generate($prompt, $options);
    }

    #[\NoDiscard]
    public function generateImage(string $prompt, array $options = []): ImageResponse
    {
        return self::service()->generateImage($prompt, $options);
    }

    #[\NoDiscard]
    public function editImage(string $imagePath, string $prompt, array $options = []): ImageResponse
    {
        return self::service()->editImage($imagePath, $prompt, $options);
    }

    #[\NoDiscard]
    public function generateImageVariation(string $imagePath, array $options = []): ImageResponse
    {
        return self::service()->generateImageVariation($imagePath, $options);
    }

    #[\NoDiscard]
    public function generateVideo(string $prompt, array $options = []): VideoResponse
    {
        return self::service()->generateVideo($prompt, $options);
    }

    #[\NoDiscard]
    public function imageToVideo(string $imagePath, ?string $prompt = null, array $options = []): VideoResponse
    {
        return self::service()->imageToVideo($imagePath, $prompt, $options);
    }

    #[\NoDiscard]
    public function generateAvatar(string $text, array $options = []): AvatarResponse
    {
        return self::service()->generateAvatar($text, $options);
    }

    #[\NoDiscard]
    public function generateAvatarWithCustomVideo(
        string $text,
        string $videoPath,
        string $videoFileName,
        array $options = []
    ): AvatarResponse {
        return self::service()->generateAvatarWithCustomVideo($text, $videoPath, $videoFileName, $options);
    }

    #[\NoDiscard]
    public function generateAvatarWithCustomAudio(
        string $audioPath,
        string $audioFileName,
        array $options = []
    ): AvatarResponse {
        return self::service()->generateAvatarWithCustomAudio($audioPath, $audioFileName, $options);
    }

    #[\NoDiscard]
    public function generateAvatarFromRequestVideo(string $text, array $fileData, array $options = []): AvatarResponse
    {
        return self::service()->generateAvatarFromRequestVideo($text, $fileData, $options);
    }

    #[\NoDiscard]
    public function generateAvatarFromRequestAudio(array $fileData, array $options = []): AvatarResponse
    {
        return self::service()->generateAvatarFromRequestAudio($fileData, $options);
    }

    #[\NoDiscard]
    public function generateAsync(string $text, array $options = []): string
    {
        return self::service()->generateAsync($text, $options);
    }

    #[\NoDiscard]
    public function getProgress(string $taskId): Progress
    {
        return self::service()->getProgress($taskId);
    }

    #[\NoDiscard]
    public function listAvatars(array $options = []): array
    {
        return self::service()->listAvatars($options);
    }

    #[\NoDiscard]
    public function listVoices(array $options = []): array
    {
        return self::service()->listVoices($options);
    }

    #[\NoDiscard]
    public function capabilities(): array
    {
        return self::service()->capabilities();
    }

    #[\NoDiscard]
    public function supports(MultimodalCapability $capability): bool
    {
        return self::service()->supports($capability);
    }

    #[\NoDiscard]
    public function platformName(): string
    {
        return self::service()->platformName();
    }

    public function getDownloadPrompt(AvatarResponse $response): string
    {
        return self::service()->getDownloadPrompt($response);
    }

    public function getFileUrl(MediaFile $file): string
    {
        return self::service()->getFileUrl($file);
    }

    public function using(string $platform): MultimodalService
    {
        return self::resolveService($platform);
    }

    public static function setDefaultService(MultimodalService $service): void
    {
        self::$defaultService = $service;
        KodeContext::set(self::CONTEXT_DEFAULT_SERVICE_KEY, $service);
    }

    public static function register(string $name, MultimodalService $service): void
    {
        self::$services[$name] = $service;
        $services = KodeContext::get(self::CONTEXT_SERVICES_KEY, []);
        $services[$name] = $service;
        KodeContext::set(self::CONTEXT_SERVICES_KEY, $services);
    }

    public function service(): MultimodalService
    {
        $service = KodeContext::get(self::CONTEXT_DEFAULT_SERVICE_KEY);
        return $service ?? self::$defaultService ?? throw ConfigurationException::missing('default_multimodal_service');
    }

    public static function reset(): void
    {
        self::$defaultService = null;
        self::$services = [];
        KodeContext::delete(self::CONTEXT_DEFAULT_SERVICE_KEY);
        KodeContext::delete(self::CONTEXT_SERVICES_KEY);
        KodeContext::delete(self::CONTEXT_FILE_UPLOADER_KEY);
        KodeContext::delete(self::CONTEXT_LOGGER_KEY);
    }

    public static function createService(
        MultimodalInterface $adapter,
        ?FileUploaderInterface $fileUploader = null,
        ?LoggerInterface $logger = null
    ): MultimodalService {
        $contextUploader = KodeContext::get(self::CONTEXT_FILE_UPLOADER_KEY);
        $contextLogger = KodeContext::get(self::CONTEXT_LOGGER_KEY);
        return new MultimodalService(
            $adapter,
            $fileUploader ?? $contextUploader ?? self::$defaultFileUploader ?? new LocalFileUploader(sys_get_temp_dir() . '/ai-agent-uploads'),
            $logger ?? $contextLogger ?? self::$defaultLogger
        );
    }

    private static function resolveService(string $name): MultimodalService
    {
        $services = KodeContext::get(self::CONTEXT_SERVICES_KEY, []);
        return $services[$name] ?? self::$services[$name] ?? throw ConfigurationException::unsupportedPlatform($name);
    }
}
