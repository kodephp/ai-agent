<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * 向量存储接口
 * 
 * 定义向量数据库的统一操作接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 * 
 * @example
 * ```php
 * $store = new MilvusVectorStore($config);
 * 
 * // 插入向量
 * $store->upsert('doc-1', $embedding, ['title' => '文档标题']);
 * 
 * // 相似度搜索
 * $results = $store->search($queryEmbedding, limit: 10);
 * ```
 */
interface VectorStoreInterface
{
    /**
     * 插入或更新向量
     *
     * @param string $id 向量唯一标识
     * @param array $vector 向量数据 (float 数组)
     * @param array $metadata 元数据
     * @return bool 是否成功
     */
    public function upsert(string $id, array $vector, array $metadata = []): bool;

    /**
     * 批量插入或更新向量
     *
     * @param array $vectors 向量数组 [['id' => string, 'vector' => array, 'metadata' => array], ...]
     * @return int 成功插入的数量
     */
    public function upsertBatch(array $vectors): int;

    /**
     * 相似度搜索
     *
     * @param array $vector 查询向量
     * @param int $limit 返回数量限制
     * @param array $filters 过滤条件
     * @return array 搜索结果 [['id' => string, 'score' => float, 'metadata' => array], ...]
     */
    public function search(array $vector, int $limit = 10, array $filters = []): array;

    /**
     * 根据 ID 获取向量
     *
     * @param string $id 向量 ID
     * @return array|null 向量数据或 null
     */
    public function get(string $id): ?array;

    /**
     * 批量获取向量
     *
     * @param array $ids 向量 ID 列表
     * @return array 向量数据数组
     */
    public function getBatch(array $ids): array;

    /**
     * 删除向量
     *
     * @param string $id 向量 ID
     * @return bool 是否成功
     */
    public function delete(string $id): bool;

    /**
     * 批量删除向量
     *
     * @param array $ids 向量 ID 列表
     * @return int 成功删除的数量
     */
    public function deleteBatch(array $ids): int;

    /**
     * 获取向量数量
     *
     * @param array $filters 过滤条件
     * @return int 向量数量
     */
    public function count(array $filters = []): int;

    /**
     * 清空所有向量
     *
     * @return bool 是否成功
     */
    public function clear(): bool;

    /**
     * 获取存储名称
     *
     * @return string 存储名称
     */
    public function name(): string;
}
