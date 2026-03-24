<?php

declare(strict_types=1);

namespace Kode\AiAgent\Agent;

use Kode\Context\Context as KodeContext;

/**
 * Agent 记忆系统
 *
 * 支持 Agent 之间共享记忆，实现上下文传递。
 * 集成 kode/context 实现协程安全的记忆存储。
 *
 * @package Kode\AiAgent\Agent
 */
final class AgentMemory
{
    private const CONTEXT_KEY = 'ai_agent.memory';
    private const MAX_MEMORY_SIZE = 100;

    private array $shortTermMemory = [];
    private array $longTermMemory = [];
    private bool $useContextStorage = false;

    public function __construct(bool $useContextStorage = false)
    {
        $this->useContextStorage = $useContextStorage;
    }

    /**
     * 存储短期记忆（会话级别）
     */
    public function remember(string $key, mixed $value, ?string $role = null): self
    {
        $entry = [
            'key' => $key,
            'value' => $value,
            'role' => $role,
            'timestamp' => microtime(true),
        ];

        $this->shortTermMemory[$key] = $entry;
        $this->persist();

        return $this;
    }

    /**
     * 存储长期记忆（持久化）
     */
    public function memorize(string $key, mixed $value, ?string $role = null): self
    {
        $entry = [
            'key' => $key,
            'value' => $value,
            'role' => $role,
            'timestamp' => microtime(true),
            'persistent' => true,
        ];

        $this->longTermMemory[$key] = $entry;
        $this->persist();

        return $this;
    }

    /**
     * 回忆记忆
     */
    public function recall(string $key, mixed $default = null): mixed
    {
        $this->load();

        if (isset($this->shortTermMemory[$key])) {
            return $this->shortTermMemory[$key]['value'];
        }

        if (isset($this->longTermMemory[$key])) {
            return $this->longTermMemory[$key]['value'];
        }

        return $default;
    }

    /**
     * 检查记忆是否存在
     */
    public function has(string $key): bool
    {
        $this->load();
        return isset($this->shortTermMemory[$key]) || isset($this->longTermMemory[$key]);
    }

    /**
     * 遗忘记忆
     */
    public function forget(string $key): self
    {
        unset($this->shortTermMemory[$key], $this->longTermMemory[$key]);
        $this->persist();
        return $this;
    }

    /**
     * 清空短期记忆
     */
    public function clearShortTerm(): self
    {
        $this->shortTermMemory = [];
        $this->persist();
        return $this;
    }

    /**
     * 清空所有记忆
     */
    public function clear(): self
    {
        $this->shortTermMemory = [];
        $this->longTermMemory = [];
        $this->persist();
        return $this;
    }

    /**
     * 获取所有记忆
     */
    public function all(): array
    {
        $this->load();
        return [
            'short_term' => $this->shortTermMemory,
            'long_term' => $this->longTermMemory,
        ];
    }

    /**
     * 按角色获取记忆
     */
    public function byRole(string $role): array
    {
        $this->load();

        $memories = [];

        foreach ($this->shortTermMemory as $entry) {
            if (($entry['role'] ?? null) === $role) {
                $memories[$entry['key']] = $entry['value'];
            }
        }

        foreach ($this->longTermMemory as $entry) {
            if (($entry['role'] ?? null) === $role) {
                $memories[$entry['key']] = $entry['value'];
            }
        }

        return $memories;
    }

    /**
     * 获取最近的记忆
     */
    public function recent(int $limit = 10): array
    {
        $this->load();

        $all = array_merge(
            array_values($this->shortTermMemory),
            array_values($this->longTermMemory)
        );

        usort($all, function ($a, $b) {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        });

        return array_slice($all, 0, $limit);
    }

    /**
     * 构建上下文提示词
     */
    public function buildContextPrompt(string $role): string
    {
        $this->load();

        $memories = $this->byRole($role);

        if (empty($memories)) {
            return '';
        }

        $prompt = "历史上下文：\n";

        foreach ($memories as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $prompt .= "- {$key}: {$value}\n";
        }

        return $prompt;
    }

    /**
     * 导出记忆
     */
    public function export(): array
    {
        $this->load();
        return [
            'short_term' => $this->shortTermMemory,
            'long_term' => $this->longTermMemory,
        ];
    }

    /**
     * 导入记忆
     */
    public function import(array $data): self
    {
        if (isset($data['short_term']) && is_array($data['short_term'])) {
            $this->shortTermMemory = $data['short_term'];
        }

        if (isset($data['long_term']) && is_array($data['long_term'])) {
            $this->longTermMemory = $data['long_term'];
        }

        $this->persist();
        return $this;
    }

    /**
     * 持久化到协程上下文
     */
    private function persist(): void
    {
        if (!$this->useContextStorage) {
            return;
        }

        $data = [
            'short_term' => $this->shortTermMemory,
            'long_term' => $this->longTermMemory,
        ];

        KodeContext::set(self::CONTEXT_KEY, $data);
    }

    /**
     * 从协程上下文加载
     */
    private function load(): void
    {
        if (!$this->useContextStorage) {
            return;
        }

        $data = KodeContext::get(self::CONTEXT_KEY, []);

        if (isset($data['short_term']) && is_array($data['short_term'])) {
            $this->shortTermMemory = $data['short_term'];
        }

        if (isset($data['long_term']) && is_array($data['long_term'])) {
            $this->longTermMemory = $data['long_term'];
        }
    }

    /**
     * 创建支持协程存储的记忆系统
     */
    public static function create(bool $useContextStorage = true): self
    {
        return new self($useContextStorage);
    }
}
