<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Kode\AiAgent\Application\Service\MultimodalService;
use Kode\AiAgent\Domain\Contract\AgentTeamInterface;
use Kode\AiAgent\Domain\Contract\AdapterInterface;
use Kode\AiAgent\Domain\Contract\ResponseInterface;
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;
use Kode\AiAgent\Log\LogManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 短剧专用 Agent 团队
 *
 * 内置编剧、画师、剪辑三个角色，支持短剧一键生成。
 *
 * @package Kode\AiAgent\Agent
 *
 * @example
 * ```php
 * $team = new ShortDramaTeam('sk-api-key');
 *
 * $result = $team->generate('一个关于友情的感人故事', [
 *     'scenes' => 5,
 *     'style' => 'cinematic',
 * ]);
 *
 * echo "视频: {$result['final_video']}\n";
 * echo "剧本: {$result['script']}\n";
 * ```
 */
class ShortDramaTeam
{
    private array $agents = [];
    private CostTracker $costTracker;
    private LoggerInterface $logger;
    private array $callbacks = [];

    private Agent $writerAgent;
    private MultimodalService $artistService;
    private Agent $editorAgent;

    public function __construct(
        string|array $apiKey,
        array $config = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->costTracker = new CostTracker();
        $this->logger = $logger ?? new NullLogger();

        $adapter = is_string($apiKey)
            ? AdapterFactory::openai($apiKey)
            : AdapterFactory::create($apiKey);

        $this->writerAgent = new Agent($adapter, array_merge([
            'model' => 'gpt-4',
        ], $config['writer'] ?? []));

        $this->artistService = new MultimodalService($adapter);

        $this->editorAgent = new Agent($adapter, array_merge([
            'model' => 'gpt-4',
        ], $config['editor'] ?? []));

        $this->agents['编剧'] = $this->writerAgent;
        $this->agents['画师'] = new Agent($adapter);
        $this->agents['剪辑'] = $this->editorAgent;

        $this->logger->debug('ShortDramaTeam 已初始化');
    }

    public function on(string $event, callable $callback): self
    {
        $this->callbacks[$event][] = $callback;
        return $this;
    }

    public function has(string $role): bool
    {
        return isset($this->agents[$role]);
    }

    public function roles(): array
    {
        return array_keys($this->agents);
    }

    public function agent(string $role): Agent
    {
        return $this->agents[$role] ?? throw new \InvalidArgumentException("未知角色: {$role}");
    }

    public function costTracker(): CostTracker
    {
        return $this->costTracker;
    }

    public function generate(string $topic, array $options = []): array
    {
        $this->fire('before_generate', $topic, $options);

        $scenes = $options['scenes'] ?? 5;
        $style = $options['style'] ?? 'cinematic';

        $this->logger->info('开始短剧生成', [
            'topic' => $topic,
            'scenes' => $scenes,
        ]);

        $script = $this->generateScript($topic, $options);

        $sceneDescriptions = $this->splitScript($script, $scenes);

        $images = $this->generateImages($sceneDescriptions, $style, $options);

        $videos = $this->generateVideos($images, $options);

        $finalVideo = $this->composeVideo($videos, $options);

        $result = [
            'topic' => $topic,
            'script' => $script,
            'scenes' => $sceneDescriptions,
            'images' => $images,
            'videos' => $videos,
            'final_video' => $finalVideo,
        ];

        $this->fire('after_generate', $result);

        return $result;
    }

    public function generateScript(string $topic, array $options = []): string
    {
        $this->fire('before_script', $topic);

        $prompt = $this->buildScriptPrompt($topic, $options);
        $response = $this->dispatch('编剧', $prompt, $options['writer_options'] ?? []);

        $this->fire('after_script', $response->content());

        return $response->content();
    }

    public function generateImages(array $scenes, string $style = 'cinematic', array $options = []): array
    {
        $this->fire('before_images', count($scenes));

        $images = [];
        foreach ($scenes as $i => $scene) {
            $description = is_array($scene) ? ($scene['description'] ?? $scene) : $scene;

            $this->logger->info("生成场景 {$i} 图像", [
                'description' => substr($description, 0, 50),
            ]);

            try {
                $imageResponse = $this->artistService->generateImage(
                    "{$style} style: {$description}",
                    $options['image_options'] ?? []
                );
                $images[$i] = [
                    'scene' => $description,
                    'url' => $imageResponse->firstImage(),
                    'response' => $imageResponse,
                ];
            } catch (\Throwable $e) {
                $this->logger->warning("场景 {$i} 图像生成失败", [
                    'error' => $e->getMessage(),
                ]);
                $images[$i] = [
                    'scene' => $description,
                    'url' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->fire('after_images', $images);

        return $images;
    }

    public function generateVideos(array $images, array $options = []): array
    {
        $this->fire('before_videos', count($images));

        $videos = [];
        foreach ($images as $i => $image) {
            if (empty($image['url'])) {
                $videos[$i] = ['url' => null, 'error' => 'no image'];
                continue;
            }

            $this->logger->info("生成场景 {$i} 视频", []);

            try {
                $videoResponse = $this->artistService->imageToVideo(
                    $image['url'],
                    "场景 {$i} 动态效果",
                    $options['video_options'] ?? []
                );
                $videos[$i] = [
                    'scene' => $image['scene'],
                    'image_url' => $image['url'],
                    'url' => $videoResponse->firstVideo(),
                    'response' => $videoResponse,
                ];
            } catch (\Throwable $e) {
                $this->logger->warning("场景 {$i} 视频生成失败", [
                    'error' => $e->getMessage(),
                ]);
                $videos[$i] = [
                    'scene' => $image['scene'],
                    'image_url' => $image['url'],
                    'url' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->fire('after_videos', $videos);

        return $videos;
    }

    public function composeVideo(array $videos, array $options = []): string
    {
        $this->fire('before_compose', count($videos));

        $validVideos = array_filter($videos, fn($v) => !empty($v['url']));

        if (empty($validVideos)) {
            throw new \RuntimeException('没有可用的视频素材');
        }

        $this->logger->info('合成最终视频', [
            'valid_videos' => count($validVideos),
        ]);

        $sceneVideos = [];
        foreach ($validVideos as $i => $video) {
            $sceneVideos[] = new \Kode\AiAgent\Drama\SceneVideo(
                sceneId: "scene-{$i}",
                order: $i,
                videoUrl: $video['url'],
                duration: 5,
                imageUrl: $video['image_url'] ?? null,
            );
        }

        $composer = new \Kode\AiAgent\Video\VideoComposerV3();
        $composer->addSceneVideos($sceneVideos);

        if (isset($options['background_music'])) {
            $composer->setBackgroundMusic(
                $options['background_music'],
                $options['music_volume'] ?? 0.3
            );
        }

        $result = $composer->compose($options['compose_options'] ?? []);

        $this->fire('after_compose', $result);

        return $result['output'] ?? '';
    }

    private function dispatch(string $role, string $task, array $options = []): ResponseInterface
    {
        $this->logger->info("Agent 分发", [
            'role' => $role,
            'task_length' => strlen($task),
        ]);

        $startTime = microtime(true);
        $response = $this->agent($role)->chat($task, $options);
        $duration = microtime(true) - $startTime;

        $this->trackCost($role, $response);

        $this->logger->info("Agent 分发完成", [
            'role' => $role,
            'duration' => round($duration, 3),
        ]);

        return $response;
    }

    private function trackCost(string $role, ResponseInterface $response): void
    {
        $usage = $response->usage();
        if (!empty($usage)) {
            $this->costTracker->track(
                $role,
                $usage['prompt_tokens'] ?? 0,
                $usage['completion_tokens'] ?? 0,
                $usage['total_tokens'] ?? 0
            );
        }
    }

    private function buildScriptPrompt(string $topic, array $options): string
    {
        $scenes = $options['scenes'] ?? 5;
        $duration = $options['duration_per_scene'] ?? 10;

        return <<<PROMPT
请为以下主题创作一个 {$scenes} 幕短剧剧本。

主题：{$topic}

要求：
1. 每幕包含场景描述和对话
2. 每幕时长约 {$duration} 秒
3. 情节完整，有起承转合
4. 语言生动，画面感强
5. 适合 AI 生成图像和视频

请按以下格式输出：
第一幕：[场景描述]
[对话内容]

第二幕：[场景描述]
[对话内容]
...
PROMPT;
    }

    private function splitScript(string $script, int $sceneCount): array
    {
        $parts = preg_split('/第[一二三四五六七八九十\d]+幕|第[\d]+场|幕\s*:/u', $script);
        $parts = array_filter(array_map('trim', $parts), fn($p) => $p !== '');

        if (count($parts) <= $sceneCount) {
            return array_values($parts);
        }

        $chunks = array_chunk(array_values($parts), (int) ceil(count($parts) / $sceneCount));
        return array_map(fn($chunk, $i) => [
            'id' => "scene-{$i}",
            'order' => $i,
            'description' => implode("\n", $chunk),
        ], array_slice($chunks, 0, $sceneCount), array_keys($chunks));
    }

    private function fire(string $event, mixed ...$args): void
    {
        if (empty($this->callbacks[$event])) {
            return;
        }

        foreach ($this->callbacks[$event] as $callback) {
            try {
                $callback(...$args);
            } catch (\Throwable $e) {
                $this->logger->warning("回调失败: {$event}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
