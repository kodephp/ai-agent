<?php

declare(strict_types=1);

namespace Kode\AiAgent\Process;

use Kode\AiAgent\Log\LogManager;

/**
 * 系统进程池
 *
 * 管理多个系统进程的并发执行，支持进程提交、批量执行、并发控制和结果回收。
 * 自动维护并发数量，常用于视频处理等需要执行外部命令的场景。
 *
 * @package Kode\AiAgent\Process
 *
 * @example
 * ```php
 * $pool = new ProcessPoolManager(maxProcesses: 4);
 *
 * // 提交视频处理任务
 * $pool->submit('ffmpeg -i input.mp4 -vf "scale=1920:1080" output_1080p.mp4');
 * $pool->submit('ffmpeg -i input.mp4 -vf "scale=1280:720" output_720p.mp4');
 *
 * // 执行并等待完成
 * $outputs = $pool->runAndWait(function ($pid, $output) {
 *     echo "[PID {$pid}] {$output}\n";
 * });
 * ```
 */
final class ProcessPoolManager
{
    /** @var SystemProcess[] 进程列表 */
    private array $processes = [];
    private int $maxProcesses;
    private int $activeCount = 0;

    /**
     * 创建进程池
     *
     * @param int $maxProcesses 最大并发进程数，默认 4
     */
    public function __construct(int $maxProcesses = 4)
    {
        $this->maxProcesses = max(1, $maxProcesses);
    }

    /**
     * 提交单个进程任务
     *
     * @param string $command 要执行的命令
     * @param array $options 配置选项
     * @return SystemProcess 进程实例
     */
    public function submit(string $command, array $options = []): SystemProcess
    {
        $process = new SystemProcess($command, $options);
        $this->processes[] = $process;

        return $process;
    }

    /**
     * 批量提交进程任务
     *
     * @param array $commands 命令数组
     * @return SystemProcess[] 进程实例数组
     */
    public function submitBatch(array $commands): array
    {
        return array_map(fn($cmd) => $this->submit($cmd), $commands);
    }

    /**
     * 执行所有进程任务
     *
     * 启动所有进程，当进程数达到上限时等待完成后再启动新进程。
     *
     * @param callable|null $onOutput 输出回调，接受 (进程ID, 输出内容) 参数
     */
    public function run(callable $onOutput = null): void
    {
        foreach ($this->processes as $process) {
            $process->start();

            if ($this->activeCount >= $this->maxProcesses) {
                $this->waitForAvailable();
            }

            $this->activeCount++;
        }

        $this->waitForAll($onOutput);
    }

    /**
     * 执行并等待所有进程完成
     *
     * @param callable|null $onOutput 输出回调
     * @return array 所有进程的输出
     */
    public function runAndWait(callable $onOutput = null): array
    {
        $this->run($onOutput);

        return array_map(fn($p) => $p->getOutput(), $this->processes);
    }

    /**
     * 检查是否有进程正在运行
     */
    public function isRunning(): bool
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 等待所有进程执行完成
     *
     * @param callable|null $onOutput 输出回调
     */
    public function waitForAll(callable $onOutput = null): void
    {
        while ($this->isRunning()) {
            foreach ($this->processes as $process) {
                if ($process->isRunning()) {
                    $output = $process->update();

                    if ($output !== null && $onOutput !== null) {
                        $onOutput($process->getPid(), $output);
                    }
                }
            }

            if ($this->activeCount >= $this->maxProcesses) {
                $this->waitForCompletion();
            }
        }
    }

    /**
     * 终止所有进程
     */
    public function terminate(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->terminate();
            }
        }
    }

    /**
     * 获取进程总数
     */
    public function count(): int
    {
        return count($this->processes);
    }

    /**
     * 获取正在运行的进程数
     */
    public function activeCount(): int
    {
        $count = 0;

        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 等待有可用槽位
     */
    private function waitForAvailable(): void
    {
        while ($this->activeCount >= $this->maxProcesses) {
            foreach ($this->processes as $process) {
                if (!$process->isRunning() && $process->getStatus() === 'completed') {
                    $this->activeCount--;
                    return;
                }
            }

            usleep(10000);
        }
    }

    /**
     * 等待进程完成
     */
    private function waitForCompletion(): void
    {
        $startTime = microtime(true);
        $timeout = 300.0;

        while ($this->activeCount >= $this->maxProcesses && (microtime(true) - $startTime) < $timeout) {
            foreach ($this->processes as $process) {
                if (!$process->isRunning()) {
                    $this->activeCount--;
                    return;
                }
            }

            usleep(10000);
        }

        if ((microtime(true) - $startTime) >= $timeout) {
            LogManager::warning('进程池等待完成超时');
        }
    }
}