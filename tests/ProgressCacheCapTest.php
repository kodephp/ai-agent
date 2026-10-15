<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tests;

use Kode\AiAgent\Domain\Model\{AvatarResponse, Progress};
use Kode\AiAgent\Infrastructure\Adapter\AbstractMultimodalAdapter;
use PHPUnit\Framework\TestCase;

/**
 * 进度缓存封顶测试（防常驻进程内存慢性泄漏）。
 *
 * 背景：AbstractMultimodalAdapter::progressCache 原本只写不删 —— 常驻 worker 里
 * 每跑一个异步任务就多一条进度，跑几十万条后就是慢性内存泄漏。现在写入口径与
 * kode/database 的 Db::QUERY_LOG_LIMIT 一致：超出上限丢最旧，保留最近 N 条。
 */
final class ProgressCacheCapTest extends TestCase
{
    public function test_进度缓存超出上限时丢最旧保留最近N条(): void
    {
        $adapter = new class extends AbstractMultimodalAdapter {
            public function name(): string
            {
                return 'cache-cap-probe';
            }

            public function generateAvatarVideo(string $text, array $options = []): AvatarResponse
            {
                return new AvatarResponse(video: 'https://cdn.example.com/v.mp4');
            }

            /** @return array<string, Progress> */
            public function cacheSnapshot(): array
            {
                return $this->progressCache;
            }

            public function seed(int $count): void
            {
                for ($i = 0; $i < $count; $i++) {
                    $this->rememberProgress(Progress::create("task-{$i}"));
                }
            }
        };

        $overLimit = AbstractMultimodalAdapter::PROGRESS_CACHE_LIMIT + 48;
        $adapter->seed($overLimit);

        $cache = $adapter->cacheSnapshot();
        self::assertCount(
            AbstractMultimodalAdapter::PROGRESS_CACHE_LIMIT,
            $cache,
            '缓存必须封顶在上限，不能随任务数无界增长'
        );

        // 丢的是最旧，保留的必须是最近 N 条（正是排查时想要的那段）
        $keys = array_keys($cache);
        self::assertSame('task-' . ($overLimit - 1), end($keys), '最新一条必须保留');
        self::assertArrayNotHasKey('task-0', $cache, '最旧的一条必须被丢弃');
        self::assertArrayHasKey('task-' . ($overLimit - AbstractMultimodalAdapter::PROGRESS_CACHE_LIMIT), $cache);
    }

    public function test_上限内的写入不受影响(): void
    {
        $adapter = new class extends AbstractMultimodalAdapter {
            public function name(): string
            {
                return 'cache-cap-probe-2';
            }

            public function generateAvatarVideo(string $text, array $options = []): AvatarResponse
            {
                return new AvatarResponse();
            }

            /** @return array<string, Progress> */
            public function cacheSnapshot(): array
            {
                return $this->progressCache;
            }

            public function seed(int $count): void
            {
                for ($i = 0; $i < $count; $i++) {
                    $this->rememberProgress(Progress::create("task-{$i}"));
                }
            }
        };

        $adapter->seed(3);

        $cache = $adapter->cacheSnapshot();
        self::assertCount(3, $cache);
        self::assertArrayHasKey('task-0', $cache, '未达上限时不应丢弃任何条目');
    }
}
