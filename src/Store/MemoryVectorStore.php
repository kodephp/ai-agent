<?php

declare(strict_types=1);

namespace Kode\AiAgent\Store;

use Kode\AiAgent\Domain\Contract\VectorStoreInterface;

/**
 * 内存向量存储
 * 
 * 用于测试和简单场景的内存向量数据库实现。
 * 使用余弦相似度进行搜索。
 * 
 * @package Kode\AiAgent\Store
 * 
 * @example
 * ```php
 * $store = new MemoryVectorStore();
 * 
 * $store->upsert('doc-1', [0.1, 0.2, 0.3], ['title' => '文档1']);
 * $store->upsert('doc-2', [0.4, 0.5, 0.6], ['title' => '文档2']);
 * 
 * $results = $store->search([0.1, 0.2, 0.3], limit: 5);
 * ```
 */
final class MemoryVectorStore implements VectorStoreInterface
{
    private array $vectors = [];
    private array $metadata = [];

    public function __construct(
        private int $dimension = 1536,
    ) {}

    public function upsert(string $id, array $vector, array $metadata = []): bool
    {
        $this->validateVector($vector);

        $this->vectors[$id] = $vector;
        $this->metadata[$id] = $metadata;

        return true;
    }

    public function upsertBatch(array $vectors): int
    {
        $count = 0;

        foreach ($vectors as $item) {
            if ($this->upsert(
                $item['id'],
                $item['vector'],
                $item['metadata'] ?? []
            )) {
                $count++;
            }
        }

        return $count;
    }

    public function search(array $vector, int $limit = 10, array $filters = []): array
    {
        $this->validateVector($vector);

        $scores = [];

        foreach ($this->vectors as $id => $storedVector) {
            if (!$this->matchFilters($this->metadata[$id] ?? [], $filters)) {
                continue;
            }

            $score = $this->cosineSimilarity($vector, $storedVector);
            $scores[$id] = $score;
        }

        arsort($scores);

        $results = [];
        $count = 0;

        foreach ($scores as $id => $score) {
            if ($count >= $limit) {
                break;
            }

            $results[] = [
                'id' => $id,
                'score' => $score,
                'metadata' => $this->metadata[$id] ?? [],
            ];
            $count++;
        }

        return $results;
    }

    public function get(string $id): ?array
    {
        if (!isset($this->vectors[$id])) {
            return null;
        }

        return [
            'id' => $id,
            'vector' => $this->vectors[$id],
            'metadata' => $this->metadata[$id] ?? [],
        ];
    }

    public function getBatch(array $ids): array
    {
        $results = [];

        foreach ($ids as $id) {
            $item = $this->get($id);
            if ($item !== null) {
                $results[] = $item;
            }
        }

        return $results;
    }

    public function delete(string $id): bool
    {
        if (!isset($this->vectors[$id])) {
            return false;
        }

        unset($this->vectors[$id], $this->metadata[$id]);

        return true;
    }

    public function deleteBatch(array $ids): int
    {
        $count = 0;

        foreach ($ids as $id) {
            if ($this->delete($id)) {
                $count++;
            }
        }

        return $count;
    }

    public function count(array $filters = []): int
    {
        if (empty($filters)) {
            return count($this->vectors);
        }

        $count = 0;
        foreach ($this->metadata as $meta) {
            if ($this->matchFilters($meta, $filters)) {
                $count++;
            }
        }

        return $count;
    }

    public function clear(): bool
    {
        $this->vectors = [];
        $this->metadata = [];

        return true;
    }

    public function name(): string
    {
        return 'memory';
    }

    private function validateVector(array $vector): void
    {
        if (count($vector) !== $this->dimension) {
            throw new \InvalidArgumentException(
                "向量维度不匹配，期望 {$this->dimension}，实际 " . count($vector)
            );
        }
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    private function matchFilters(array $metadata, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if (!isset($metadata[$key]) || $metadata[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
