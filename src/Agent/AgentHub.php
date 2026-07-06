<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, MultimodalInterface};
use Kode\AiAgent\Infrastructure\Adapter\AdapterFactory;
use Kode\AiAgent\Infrastructure\Adapter\OpenAiAdapter;

/**
 * Agent 中心 - 单 Key 多 Agent 管理
 *
 * 使用一个 API Key 创建和管理多个 Agent，支持不同角色和功能。
 *
 * @package Kode\AiAgent\Agent
 *
 * @example
 * ```php
 * // 创建一个 Key，管理多个 Agent
 * $hub = AgentHub::create('sk-api-key');
 *
 * // 获取编剧 Agent
 * $writer = $hub->writer(['model' => 'gpt-4']);
 *
 * // 获取画师 Agent (多模态)
 * $artist = $hub->artist(['model' => 'dall-e-3']);
 *
 * // 获取剪辑 Agent
 * $editor = $hub->editor(['model' => 'sora']);
 *
 * // 或者直接使用
 * $hub->chat('writer', '写一个关于友情的剧本');
 * $hub->image('生成一幅山水画');
 * $hub->video('生成一段风景视频');
 * ```
 */
class AgentHub
{
    private string $apiKey;
    private array $config;
    private AdapterInterface $adapter;
    private ?MultimodalInterface $multimodalAdapter;
    private array $agents = [];
    private array $sharedOptions = [];
    private static ?self $instance = null;

    public function __construct(
        string $apiKey,
        array $config = [],
        ?AdapterInterface $adapter = null,
        ?MultimodalInterface $multimodalAdapter = null,
    ) {
        $this->apiKey = $apiKey;
        $this->config = $config;
        $this->adapter = $adapter ?? AdapterFactory::openai($this->apiKey, $config);
        $this->multimodalAdapter = $multimodalAdapter;
        $this->sharedOptions = [
            'api_key' => $this->apiKey,
            'base_url' => $config['base_url'] ?? null,
            'timeout' => $config['timeout'] ?? 30,
        ];
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public static function create(string $apiKey, array $config = []): self
    {
        return new self($apiKey, $config);
    }

    public static function getInstance(?string $apiKey = null, array $config = []): self
    {
        if (self::$instance === null && $apiKey !== null) {
            self::$instance = new self($apiKey, $config);
        }

        if (self::$instance === null) {
            throw new \RuntimeException('AgentHub 未初始化，请先调用 getInstance($apiKey)');
        }

        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function writer(array $options = []): Agent
    {
        $key = 'writer';
        if (!isset($this->agents[$key])) {
            $this->agents[$key] = new Agent(
                $this->adapter,
                array_merge(['model' => 'gpt-4'], $this->sharedOptions, $options)
            );
        }
        return $this->agents[$key];
    }

    public function artist(array $options = []): Agent
    {
        $key = 'artist';
        if (!isset($this->agents[$key])) {
            $this->agents[$key] = new Agent(
                $this->adapter,
                array_merge(['model' => 'gpt-4-vision-preview'], $this->sharedOptions, $options)
            );
        }
        return $this->agents[$key];
    }

    public function editor(array $options = []): Agent
    {
        $key = 'editor';
        if (!isset($this->agents[$key])) {
            $this->agents[$key] = new Agent(
                $this->adapter,
                array_merge(['model' => 'gpt-4'], $this->sharedOptions, $options)
            );
        }
        return $this->agents[$key];
    }

    public function analyst(array $options = []): Agent
    {
        $key = 'analyst';
        if (!isset($this->agents[$key])) {
            $this->agents[$key] = new Agent(
                $this->adapter,
                array_merge(['model' => 'gpt-4'], $this->sharedOptions, $options)
            );
        }
        return $this->agents[$key];
    }

    public function executor(array $options = []): Agent
    {
        $key = 'executor';
        if (!isset($this->agents[$key])) {
            $this->agents[$key] = new Agent(
                $this->adapter,
                array_merge(['model' => 'gpt-4'], $this->sharedOptions, $options)
            );
        }
        return $this->agents[$key];
    }

    public function register(string $name, array $options = []): Agent
    {
        if (!isset($this->agents[$name])) {
            $this->agents[$name] = new Agent(
                $this->adapter,
                array_merge($this->sharedOptions, $options)
            );
        }
        return $this->agents[$name];
    }

    public function get(string $name): Agent
    {
        return $this->agents[$name] ?? throw new \InvalidArgumentException("未知 Agent: {$name}");
    }

    public function has(string $name): bool
    {
        return isset($this->agents[$name]);
    }

    public function chat(string $agentName, string $message, array $options = []): \Kode\AiAgent\Domain\Contract\ResponseInterface
    {
        return $this->get($agentName)->chat($message, $options);
    }

    public function image(string $prompt, array $options = []): \Kode\AiAgent\Domain\Contract\ResponseInterface
    {
        return $this->artist($options)->chat(
            "请用 DALL-E 生成图像: {$prompt}",
            $options
        );
    }

    public function video(string $prompt, array $options = []): \Kode\AiAgent\Domain\Contract\ResponseInterface
    {
        return $this->editor($options)->chat(
            "请用 Sora 生成视频: {$prompt}",
            $options
        );
    }

    public function script(string $topic, array $options = []): string
    {
        $generator = new ScriptGenerator($this->adapter, $this->sharedOptions);
        return $generator->generate($topic, $options);
    }

    public function shortDrama(string $topic, array $options = []): array
    {
        if ($this->multimodalAdapter === null) {
            throw new \InvalidArgumentException('生成短剧需要多模态适配器，请通过 multimodalAdapter 参数传入');
        }

        $team = new ShortDramaTeam(
            $this->adapter,
            $this->multimodalAdapter,
            array_merge($this->config, $options)
        );
        return $team->generate($topic, $options);
    }

    public function team(callable $callback): array
    {
        $team = new RoleAgentTeam();

        foreach ($this->agents as $name => $agent) {
            $team->assign($name, $agent);
        }

        $result = $callback($team, $this);

        return $result ?? [
            'hub' => $this,
            'team' => $team,
            'agents' => array_keys($this->agents),
        ];
    }

    public function parallel(array $tasks, array $options = []): array
    {
        $maxConcurrency = $options['concurrency'] ?? 4;
        $results = [];

        $chunks = array_chunk($tasks, $maxConcurrency);
        foreach ($chunks as $chunk) {
            foreach ($chunk as $task) {
                $agentName = $task['agent'] ?? 'writer';
                $message = $task['message'] ?? '';
                $opts = $task['options'] ?? [];

                $results[] = [
                    'agent' => $agentName,
                    'response' => $this->chat($agentName, $message, $opts),
                ];
            }
        }

        return $results;
    }

    public function pipeline(callable ...$stages): \Kode\AiAgent\Domain\Contract\ResponseInterface
    {
        $context = [];
        $lastResult = null;

        foreach ($stages as $index => $stage) {
            $result = $stage($context, $this);
            $lastResult = $result;
            $context["stage_{$index}_result"] = $result;

            if ($result instanceof \Kode\AiAgent\Domain\Contract\ResponseInterface) {
                $context["stage_{$index}_content"] = $result->content();
            } else {
                $context["stage_{$index}_content"] = $result;
            }
        }

        return $lastResult ?? throw new \RuntimeException('Pipeline produced no result');
    }

    public function agents(): array
    {
        return array_keys($this->agents);
    }

    public function adapter(): AdapterInterface
    {
        return $this->adapter;
    }

    public function config(): array
    {
        return $this->config;
    }
}
