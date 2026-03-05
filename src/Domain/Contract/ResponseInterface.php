<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * 响应接口
 * 
 * 定义 AI 响应的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 */
interface ResponseInterface
{
    /**
     * 获取响应内容
     *
     * @return string 响应文本
     */
    public function content(): string;

    /**
     * 获取选项列表
     *
     * @return array 选项数组
     */
    public function choices(): array;

    /**
     * 获取 Token 使用量
     *
     * @return array 使用量统计
     */
    public function usage(): array;

    /**
     * 检查是否为流式响应
     *
     * @return bool 是否流式
     */
    public function isStream(): bool;

    /**
     * 获取业务状态码
     *
     * @return int 状态码 (0=成功)
     */
    public function code(): int;

    /**
     * 获取消息描述
     *
     * @return string 消息
     */
    public function msg(): string;

    /**
     * 获取方法耗时
     *
     * @return float 耗时(秒)
     */
    public function duration(): float;

    /**
     * 检查是否成功
     *
     * @return bool 是否成功
     */
    public function isSuccess(): bool;

    /**
     * 转换为数组
     *
     * @return array 响应数组
     */
    public function toArray(): array;

    /**
     * 转换为 JSON
     *
     * @param int $flags JSON 编码标志
     * @return string JSON 字符串
     */
    public function toJson(int $flags = 0): string;

    /**
     * 创建新响应并修改指定字段
     *
     * @param array $values 要修改的字段
     * @return static 新响应实例
     */
    public function with(array $values): static;
}
