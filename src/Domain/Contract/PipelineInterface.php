<?php

declare(strict_types=1);

namespace Kode\AiAgent\Domain\Contract;

/**
 * 管道接口
 * 
 * 定义数据处理管道的统一接口。
 * 
 * @package Kode\AiAgent\Domain\Contract
 */
interface PipelineInterface
{
    /**
     * 添加处理阶段
     *
     * @param callable $stage 处理函数
     * @return static 当前实例
     */
    public function pipe(callable $stage): static;

    /**
     * 执行管道处理
     *
     * @param mixed $input 输入数据
     * @return mixed 处理结果
     */
    public function process(mixed $input): mixed;

    /**
     * 重置管道
     *
     * @return static 当前实例
     */
    public function reset(): static;
}
