<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Kode\AiAgent\Domain\Contract\AdapterInterface;
use Kode\AiAgent\Domain\Model\Response;

/**
 * 剧本生成器
 *
 * 专门用于生成短剧剧本，支持结构化输出、角色管理和场景拆分。
 *
 * @package Kode\AiAgent\Agent
 *
 * @example
 * ```php
 * $generator = new ScriptGenerator($adapter);
 *
 * $script = $generator->generate('友情主题', [
 *     'scenes' => 5,
 *     'duration' => 10,
 * ]);
 *
 * $parsed = $generator->parse($script);
 * echo "角色: " . implode(', ', $parsed['characters']);
 * ```
 */
class ScriptGenerator
{
    private AdapterInterface $adapter;
    private array $defaultOptions = [
        'scenes' => 5,
        'duration_per_scene' => 10,
        'style' => 'cinematic',
        'language' => 'zh-CN',
    ];

    public function __construct(AdapterInterface $adapter, array $defaultOptions = [])
    {
        $this->adapter = $adapter;
        $this->defaultOptions = array_merge($this->defaultOptions, $defaultOptions);
    }

    public function generate(string $topic, array $options = []): string
    {
        $options = array_merge($this->defaultOptions, $options);
        $prompt = $this->buildPrompt($topic, $options);

        $response = $this->adapter->send(
            new \Kode\AiAgent\Domain\Model\Prompt($prompt),
            $options
        );

        return $response->content();
    }

    public function generateWithRoles(string $topic, array $options = []): array
    {
        $options = array_merge($this->defaultOptions, $options);
        $options['include_roles'] = true;

        $script = $this->generate($topic, $options);
        return $this->parse($script);
    }

    public function parse(string $script): array
    {
        $result = [
            'raw' => $script,
            'scenes' => [],
            'characters' => [],
            'dialogues' => [],
        ];

        $scenePattern = '/第[一二三四五六七八九十\d]+幕[：:](.*?)(?=第[一二三四五六七八九十\d]+幕|$)/us';
        if (preg_match_all($scenePattern, $script, $matches)) {
            foreach ($matches[1] as $index => $sceneContent) {
                $result['scenes'][] = [
                    'index' => $index + 1,
                    'content' => trim($sceneContent),
                ];
            }
        }

        $characterPattern = '/【([^】]+)】([^【\n]+)/u';
        if (preg_match_all($characterPattern, $script, $matches)) {
            $result['characters'] = array_unique($matches[1]);
            foreach ($matches[1] as $index => $character) {
                $result['dialogues'][] = [
                    'character' => trim($character),
                    'text' => trim($matches[2][$index]),
                ];
            }
        }

        return $result;
    }

    public function splitScenes(string $script, int $sceneCount = null): array
    {
        $parsed = $this->parse($script);

        if ($sceneCount !== null && count($parsed['scenes']) > $sceneCount) {
            $chunks = array_chunk($parsed['scenes'], (int) ceil(count($parsed['scenes']) / $sceneCount));
            return array_map(fn($chunk) => [
                'description' => implode("\n", array_column($chunk, 'content')),
                'scenes' => array_column($chunk, 'index'),
            ], array_slice($chunks, 0, $sceneCount));
        }

        return array_map(fn($scene) => [
            'description' => $scene['content'],
            'scenes' => [$scene['index']],
        ], $parsed['scenes']);
    }

    public function enhance(string $script, array $options = []): string
    {
        $prompt = <<<PROMPT
请增强以下剧本，增加更多细节描写和情感表达：

{$script}

要求：
1. 增加场景氛围描写
2. 丰富角色心理活动
3. 添加更多对话细节
4. 保持原有剧情结构

请直接输出增强后的剧本：
PROMPT;

        $response = $this->adapter->send(
            new \Kode\AiAgent\Domain\Model\Prompt($prompt),
            $options
        );

        return $response->content();
    }

    public function addTransitions(string $script, array $options = []): string
    {
        $transitions = $options['transitions'] ?? ['fade', 'dissolve', 'slide'];

        $prompt = <<<PROMPT
请为以下剧本添加转场描述，使用 [{implode(', ', $transitions)}] 等转场效果：

{$script}

格式示例：
【转场：淡入】
第一幕：...
【转场：溶解】
第二幕：...

请直接输出带转场的剧本：
PROMPT;

        $response = $this->adapter->send(
            new \Kode\AiAgent\Domain\Model\Prompt($prompt),
            $options
        );

        return $response->content();
    }

    private function buildPrompt(string $topic, array $options): string
    {
        $scenes = $options['scenes'];
        $duration = $options['duration_per_scene'];
        $style = $options['style'];
        $language = $options['language'];

        $languageHint = $language === 'zh-CN' ? '中文' : '英文';

        return <<<PROMPT
请为以下主题创作一个 {$scenes} 幕短剧剧本。

主题：{$topic}

要求：
1. 每幕包含场景描述和角色对话
2. 每幕时长约 {$duration} 秒
3. 风格：{$style}
4. 语言：{$languageHint}
5. 情节完整，有起承转合
6. 语言生动，画面感强
7. 适合 AI 生成图像和视频

格式要求：
- 使用【角色名】表示对话
- 使用【场景描述】表示场景
- 使用【转场：效果】表示转场

请按以下格式输出：
【场景描述】
时间、地点、氛围描写

【角色名】对话内容

【转场：淡入】
...
PROMPT;
    }
}
