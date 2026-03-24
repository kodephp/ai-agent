<?php

declare(strict_types=1);

namespace Kode\AiAgent\Process;

use Kode\AiAgent\Log\LogManager;

/**
 * 系统进程封装类
 *
 * 基于 proc_open/pcntl 实现系统进程管理，支持进程启动、监控、等待、终止等操作。
 * 用于执行外部命令（如 ffmpeg 视频处理）并获取执行结果。
 *
 * @package Kode\AiAgent\Process
 *
 * @example
 * ```php
 * $process = new SystemProcess('ffmpeg -i input.mp4 output.mp4', [
 *     'timeout' => 300,
 * ]);
 *
 * $process->start();
 * $process->wait();
 *
 * echo $process->getOutput();
 * echo "Exit code: " . $process->getExitCode();
 * ```
 */
final class SystemProcess
{
    private string $command;
    private array $options;
    private ?int $pid = null;
    /** @var resource|false */
    private $handle = false;
    private string $output = '';
    private string $errorOutput = '';
    private string $status = 'pending';
    private int $exitCode = -1;
    private float $startTime = 0;
    private float $endTime = 0;

    /**
     * 创建进程实例
     *
     * @param string $command 要执行的命令
     * @param array $options 配置选项
     *   - cwd: 工作目录
     *   - env: 环境变量
     *   - timeout: 超时时间（秒），默认 300
     *   - buffer_size: 缓冲区大小，默认 8192
     */
    public function __construct(string $command, array $options = [])
    {
        $this->command = $command;
        $this->options = array_merge([
            'cwd' => null,
            'env' => null,
            'timeout' => 300,
            'buffer_size' => 8192,
        ], $options);
    }

    /**
     * 启动进程
     *
     * @return $this
     * @throws \RuntimeException 进程已启动或启动失败时抛出
     */
    public function start(): self
    {
        if ($this->status !== 'pending') {
            throw new \RuntimeException("进程已启动 (状态: {$this->status})");
        }

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = $this->options['cwd'] ?? null;
        $env = $this->options['env'] ?? null;

        $this->handle = proc_open(
            $this->command,
            $descriptor,
            $pipes,
            $cwd,
            $env
        );

        if ($this->handle === false) {
            throw new \RuntimeException("进程启动失败: {$this->command}");
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $status = proc_get_status($this->handle);
        $this->pid = $status['pid'] ?? null;
        $this->status = 'running';
        $this->startTime = microtime(true);

        LogManager::info('进程已启动', [
            'pid' => $this->pid,
            'command' => $this->command,
        ]);

        return $this;
    }

    /**
     * 更新进程状态并读取输出
     *
     * 非阻塞读取，用于监控进程执行进度。
     *
     * @return string|null 标准输出内容
     */
    public function update(): ?string
    {
        if ($this->status !== 'running' || $this->handle === false) {
            return null;
        }

        $output = '';
        $status = proc_get_status($this->handle);

        if (isset($status['pipe']) && is_resource($status['pipe'])) {
            while ($data = fread($status['pipe'], $this->options['buffer_size'])) {
                $output .= $data;
            }
        }

        if (isset($status['pipe']) && is_resource($status['pipe'])) {
            while ($data = fread($status['pipe'], $this->options['buffer_size'])) {
                $this->errorOutput .= $data;
            }
        }

        if (!$status['running']) {
            $this->status = 'completed';
            $this->exitCode = $status['exitcode'] ?? -1;
            $this->endTime = microtime(true);

            LogManager::info('进程执行完成', [
                'pid' => $this->pid,
                'exit_code' => $this->exitCode,
                'duration' => $this->duration(),
            ]);
        }

        return $output;
    }

    /**
     * 等待进程执行完成
     *
     * 阻塞等待，直到进程执行结束。
     *
     * @return $this
     */
    public function wait(): self
    {
        if ($this->status !== 'running') {
            return $this;
        }

        while ($this->isRunning()) {
            $this->update();
            usleep(1000);
        }

        return $this;
    }

    /**
     * 优雅终止进程 (SIGTERM)
     *
     * @return $this
     */
    public function terminate(): self
    {
        if ($this->status === 'running' && $this->handle !== false) {
            proc_terminate($this->handle, SIGTERM);
            $this->status = 'terminated';
            $this->endTime = microtime(true);

            LogManager::warning('进程已终止', ['pid' => $this->pid]);
        }

        return $this;
    }

    /**
     * 强制杀死进程 (SIGKILL)
     *
     * @return $this
     */
    public function kill(): self
    {
        if ($this->status === 'running' && $this->handle !== false) {
            proc_terminate($this->handle, SIGKILL);
            $this->status = 'killed';
            $this->endTime = microtime(true);

            LogManager::error('进程已被杀死', ['pid' => $this->pid]);
        }

        return $this;
    }

    /**
     * 检查进程是否正在运行
     */
    public function isRunning(): bool
    {
        return $this->status === 'running' && $this->handle !== false;
    }

    /**
     * 获取进程 ID
     */
    public function getPid(): ?int
    {
        return $this->pid;
    }

    /**
     * 获取进程状态
     *
     * @return string pending|running|completed|terminated|killed
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * 获取进程退出码
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * 获取标准输出
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    /**
     * 获取错误输出
     */
    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    /**
     * 获取执行耗时（秒）
     */
    public function duration(): float
    {
        if ($this->startTime === 0) {
            return 0;
        }

        $end = $this->endTime ?: microtime(true);
        return round($end - $this->startTime, 3);
    }

    /**
     * 析构函数
     *
     * 确保进程资源被正确释放。
     */
    public function __destruct()
    {
        if ($this->handle !== false) {
            if (is_resource($this->handle)) {
                proc_close($this->handle);
            }
            $this->handle = false;
        }
    }
}