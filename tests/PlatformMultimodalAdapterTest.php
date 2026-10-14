<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Application\Service\MultimodalService;
use Kode\AiAgent\Domain\Contract\{AdapterInterface, AvatarInterface, ImageGeneratorInterface, VideoGeneratorInterface};
use Kode\AiAgent\Domain\Model\{AvatarResponse, ImageResponse, VideoResponse};
use Kode\AiAgent\Domain\ValueObject\MultimodalCapability;
use Kode\AiAgent\Exception\{ConfigurationException, InvalidInputException, PlatformException};
use Kode\AiAgent\Infrastructure\Adapter\PlatformMultimodalAdapter;
use Kode\AiAgent\Support\Builder\MultimodalBuilder;
use PHPUnit\Framework\TestCase;

/**
 * PlatformMultimodalAdapter 桥接适配器测试
 *
 * 覆盖：能力自动推断、显式覆盖、委托转发、缺委托精确报错、
 * MultimodalService 能力门打通（修复 supports() 恒 false 的历史缺口）。
 */
final class PlatformMultimodalAdapterTest extends TestCase
{
    public function testConstructorRequiresAtLeastOneDelegate(): void
    {
        $this->expectException(ConfigurationException::class);
        new PlatformMultimodalAdapter();
    }

    public function testFromAdapterRejectsChatOnlyAdapter(): void
    {
        $chatOnly = $this->createMock(AdapterInterface::class);

        $this->expectException(ConfigurationException::class);
        PlatformMultimodalAdapter::fromAdapter($chatOnly);
    }

    public function testImageOnlyCapabilityInference(): void
    {
        $bridge = PlatformMultimodalAdapter::fromAdapter($this->createMock(ImageGeneratorInterface::class));

        self::assertTrue($bridge->supports(MultimodalCapability::TEXT_TO_IMAGE));
        self::assertTrue($bridge->supports(MultimodalCapability::IMAGE_EDIT));
        self::assertTrue($bridge->supports(MultimodalCapability::IMAGE_VARIATION));
        self::assertTrue($bridge->supports(MultimodalCapability::AVATAR_LIST));
        self::assertTrue($bridge->supports(MultimodalCapability::VOICE_LIST));

        self::assertFalse($bridge->supports(MultimodalCapability::TEXT_TO_VIDEO));
        self::assertFalse($bridge->supports(MultimodalCapability::IMAGE_TO_VIDEO));
        self::assertFalse($bridge->supports(MultimodalCapability::AVATAR_GENERATION));
        self::assertFalse($bridge->supports(MultimodalCapability::ASYNC_GENERATION));
        self::assertFalse($bridge->supports(MultimodalCapability::PROGRESS_TRACKING));
    }

    public function testVideoOnlyCapabilityInference(): void
    {
        $bridge = PlatformMultimodalAdapter::fromAdapter($this->createMock(VideoGeneratorInterface::class));

        self::assertTrue($bridge->supports(MultimodalCapability::TEXT_TO_VIDEO));
        self::assertTrue($bridge->supports(MultimodalCapability::IMAGE_TO_VIDEO));

        self::assertFalse($bridge->supports(MultimodalCapability::TEXT_TO_IMAGE));
        self::assertFalse($bridge->supports(MultimodalCapability::IMAGE_EDIT));
    }

    public function testAvatarOnlyCapabilityInference(): void
    {
        $bridge = PlatformMultimodalAdapter::fromAdapter($this->createMock(AvatarInterface::class));

        self::assertTrue($bridge->supports(MultimodalCapability::AVATAR_GENERATION));
        self::assertTrue($bridge->supports(MultimodalCapability::AVATAR_CUSTOM_VIDEO));
        self::assertTrue($bridge->supports(MultimodalCapability::AVATAR_CUSTOM_AUDIO));
        self::assertTrue($bridge->supports(MultimodalCapability::ASYNC_GENERATION));
        self::assertTrue($bridge->supports(MultimodalCapability::PROGRESS_TRACKING));

        self::assertFalse($bridge->supports(MultimodalCapability::TEXT_TO_IMAGE));
    }

    public function testExplicitCapabilitiesOverrideInference(): void
    {
        $bridge = new PlatformMultimodalAdapter(
            imageGenerator: $this->createMock(ImageGeneratorInterface::class),
            explicitCapabilities: [MultimodalCapability::TEXT_TO_IMAGE],
        );

        self::assertTrue($bridge->supports(MultimodalCapability::TEXT_TO_IMAGE));
        // 显式列表完全取代推断：编辑能力与恒可用的列表能力都被关闭
        self::assertFalse($bridge->supports(MultimodalCapability::IMAGE_EDIT));
        self::assertFalse($bridge->supports(MultimodalCapability::AVATAR_LIST));
    }

    public function testPlatformNameFromConstructorAndFromAdapter(): void
    {
        $custom = new PlatformMultimodalAdapter(
            imageGenerator: $this->createMock(ImageGeneratorInterface::class),
            platformName: 'custom-bridge',
        );
        self::assertSame('custom-bridge', $custom->name());

        $adapter = $this->createMockForIntersectionOfInterfaces([
            ImageGeneratorInterface::class,
            AdapterInterface::class,
        ]);
        $adapter->method('name')->willReturn('openai');

        $bridge = PlatformMultimodalAdapter::fromAdapter($adapter);
        self::assertSame('openai', $bridge->name());
    }

    public function testImageGeneratorDelegation(): void
    {
        $expected = new ImageResponse(images: ['https://cdn.example.com/a.png']);
        $image = $this->createMock(ImageGeneratorInterface::class);
        $image->expects(self::once())
            ->method('generateImage')
            ->with('a cat', ['n' => 1])
            ->willReturn($expected);

        $bridge = new PlatformMultimodalAdapter(imageGenerator: $image);

        self::assertSame($expected, $bridge->generateImage('a cat', ['n' => 1]));
    }

    public function testImageEditAndVariationDelegation(): void
    {
        $edited = new ImageResponse(images: ['https://cdn.example.com/e.png']);
        $varied = new ImageResponse(images: ['https://cdn.example.com/v.png']);

        $image = $this->createMock(ImageGeneratorInterface::class);
        $image->expects(self::once())->method('editImage')->with('img', 'prompt', [])->willReturn($edited);
        $image->expects(self::once())->method('generateImageVariation')->with('img', [])->willReturn($varied);

        $bridge = new PlatformMultimodalAdapter(imageGenerator: $image);

        self::assertSame($edited, $bridge->editImage('img', 'prompt'));
        self::assertSame($varied, $bridge->generateImageVariation('img'));
    }

    public function testVideoGeneratorDelegation(): void
    {
        $expected = new VideoResponse();
        $video = $this->createMock(VideoGeneratorInterface::class);
        $video->expects(self::once())->method('generateVideo')->with('run', [])->willReturn($expected);
        $video->expects(self::once())->method('imageToVideo')->with('img', 'go', [])->willReturn($expected);

        $bridge = new PlatformMultimodalAdapter(videoGenerator: $video);

        self::assertSame($expected, $bridge->generateVideo('run'));
        self::assertSame($expected, $bridge->imageToVideo('img', 'go'));
    }

    public function testAvatarProviderDelegationPrefersProviderOverBaseDefaults(): void
    {
        $avatarResponse = new AvatarResponse(video: 'https://cdn.example.com/avatar.mp4');
        $providerVoices = [['id' => 'custom-voice', 'name' => '自定义音色']];

        $avatar = $this->createMock(AvatarInterface::class);
        $avatar->expects(self::once())
            ->method('generateAvatarVideo')
            ->with('你好', [])
            ->willReturn($avatarResponse);
        $avatar->method('listVoices')->willReturn($providerVoices);

        $bridge = new PlatformMultimodalAdapter(avatarProvider: $avatar);

        self::assertSame($avatarResponse, $bridge->generateAvatarVideo('你好'));
        // 提供方列表优先于基类内置默认列表
        self::assertSame($providerVoices, $bridge->listVoices());
    }

    public function testListsWithoutAvatarProviderFallBackToBaseDefaults(): void
    {
        $bridge = new PlatformMultimodalAdapter(
            imageGenerator: $this->createMock(ImageGeneratorInterface::class),
        );

        $avatars = $bridge->listAvatars();
        self::assertCount(2, $avatars);
        self::assertSame('default-female', $avatars[0]['id']);
    }

    public function testMissingDelegateThrowsPreciseCapabilityError(): void
    {
        $bridge = new PlatformMultimodalAdapter(
            videoGenerator: $this->createMock(VideoGeneratorInterface::class),
        );

        try {
            $bridge->generateImage('a cat');
            self::fail('缺少图像委托时应抛出 PlatformException');
        } catch (PlatformException $e) {
            self::assertSame(1004, $e->errorCode());
            self::assertStringContainsString('未配置文本生成图像能力', $e->getMessage());
            self::assertSame('text_to_image', $e->context()['capability'] ?? null);
            self::assertSame('platform', $e->context()['platform'] ?? null);
        }
    }

    public function testGenerateDispatchesByOutputType(): void
    {
        $imageResponse = new ImageResponse(images: ['https://cdn.example.com/a.png']);
        $videoResponse = new VideoResponse();

        $image = $this->createMock(ImageGeneratorInterface::class);
        $image->method('generateImage')->willReturn($imageResponse);

        $video = $this->createMock(VideoGeneratorInterface::class);
        $video->method('generateVideo')->willReturn($videoResponse);

        $bridge = new PlatformMultimodalAdapter(imageGenerator: $image, videoGenerator: $video);

        self::assertSame($imageResponse, $bridge->generate('a cat'));
        self::assertSame($videoResponse, $bridge->generate('a cat', ['output_type' => 'video']));

        $this->expectException(InvalidInputException::class);
        $bridge->generate('a cat', ['output_type' => 'audio']);
    }

    public function testMultimodalServiceCapabilityGateIsWired(): void
    {
        $expected = new ImageResponse(images: ['https://cdn.example.com/a.png']);
        $image = $this->createMock(ImageGeneratorInterface::class);
        $image->method('generateImage')->willReturn($expected);

        /** @var MultimodalService $service */
        $service = MultimodalBuilder::create()
            ->withAdapter(new PlatformMultimodalAdapter(imageGenerator: $image, platformName: 'openai'))
            ->build();

        // 修复前 supports() 恒 false，此处一律抛「当前平台不支持能力」
        self::assertTrue($service->supports(MultimodalCapability::TEXT_TO_IMAGE));
        self::assertFalse($service->supports(MultimodalCapability::TEXT_TO_VIDEO));
        self::assertSame($expected, $service->generateImage('a cat'));
        self::assertSame('openai', $service->platformName());

        // 视频不在能力列表：服务层门禁在触达适配器之前拦截
        try {
            $service->generateVideo('run');
            self::fail('视频能力未声明时服务层应拦截');
        } catch (PlatformException $e) {
            self::assertStringContainsString('当前平台不支持能力', $e->getMessage());
        }
    }

    public function testBuilderWithPlatformIntegratesAdapterFactory(): void
    {
        $service = MultimodalBuilder::create()
            ->withPlatform('openai', ['api_key' => 'sk-test-key-0123456789abcdef'])
            ->build();

        self::assertSame('openai', $service->platformName());
        self::assertTrue($service->supports(MultimodalCapability::TEXT_TO_IMAGE));
        self::assertTrue($service->supports(MultimodalCapability::IMAGE_EDIT));
        self::assertFalse($service->supports(MultimodalCapability::TEXT_TO_VIDEO));
    }
}
