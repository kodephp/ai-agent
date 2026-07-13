<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support\Facade;

use Kode\AiAgent\Drama\Director\{DramaDirector, DramaResult, DramaSegment, ModelBinding};
use Kode\AiAgent\VideoGateway\VideoGateway;
use Kode\Context\Context as KodeContext;
use Kode\Facade\Facade;

/**
 * 漫剧导演门面
 *
 * 提供简洁的静态调用接口访问 DramaDirector。
 *
 * @package Kode\AiAgent\Support\Facade
 *
 * @example
 * ```php
 * // 设置统一视频网关（已配置各供应商）
 * Drama::setGateway($videoGateway);
 *
 * // 一键生成漫剧
 * $result = Drama::generate("场景1：清晨的街道\n@model seedance-2.5-pro\n\n场景2：两人相遇");
 *
 * // 单段重生成
 * Drama::regenerateSegment(0, ['prompt' => '新的提示词']);
 *
 * // 重新合成
 * $result = Drama::compose();
 * ```
 *
 * @method static DramaResult generate(string|array $script, array $options = [])
 * @method static DramaSegment regenerateSegment(int $index, array $options = [], ?string $newPrompt = null)
 * @method static DramaResult compose(array $options = [])
 * @method static DramaSegment[] segments()
 * @method static DramaDirector director()
 * @method static void reset()
 */
final class Drama extends Facade
{
    private const CONTEXT_KEY = 'ai_agent.drama.director';

    private static ?DramaDirector $default = null;

    protected static function id(): string
    {
        return 'drama';
    }

    public static function getInstance(): object
    {
        return new self();
    }

    /**
     * 绑定统一视频网关（创建导演）
     */
    public function setGateway(VideoGateway $gateway): void
    {
        $director = new DramaDirector($gateway);
        KodeContext::set(self::CONTEXT_KEY, $director);
        self::$default = $director;
    }

    public function director(): DramaDirector
    {
        $director = KodeContext::get(self::CONTEXT_KEY);
        if ($director instanceof DramaDirector) {
            return $director;
        }

        if (self::$default === null) {
            throw new \RuntimeException('请先通过 Drama::setGateway($videoGateway) 绑定视频网关');
        }

        return self::$default;
    }

    #[\NoDiscard]
    public function generate(string|array $script, array $options = []): DramaResult
    {
        return self::director()->generate($script, $options);
    }

    #[\NoDiscard]
    public function regenerateSegment(int $index, array $options = [], ?string $newPrompt = null): DramaSegment
    {
        return self::director()->regenerateSegment($index, $options, $newPrompt);
    }

    #[\NoDiscard]
    public function compose(array $options = []): DramaResult
    {
        return self::director()->compose($options);
    }

    /**
     * @return array<int, DramaSegment>
     */
    public function segments(): array
    {
        return self::director()->segments();
    }

    public function reset(): void
    {
        KodeContext::delete(self::CONTEXT_KEY);
        self::$default = null;
    }
}
