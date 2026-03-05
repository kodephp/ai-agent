<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * 消息接口
 * 
 * 定义聊天消息的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 */
interface MessageInterface
{
    /**
     * 获取角色
     *
     * @return string 角色 (user, assistant, system)
     */
    public function role(): string;

    /**
     * 获取内容
     *
     * @return string 消息内容
     */
    public function content(): string;

    /**
     * 获取名称 (可选)
     *
     * @return string|null 名称
     */
    public function name(): ?string;

    /**
     * 转换为数组
     *
     * @return array 消息数组
     */
    public function toArray(): array;
}
