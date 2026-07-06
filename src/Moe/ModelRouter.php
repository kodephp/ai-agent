<?php

declare(strict_types=1);

namespace Kode\AiAgent\Moe;

use Kode\AiAgent\Domain\Contract\{AdapterInterface, PromptInterface, ResponseInterface};
use Kode\AiAgent\Moe\Contract\{ExpertInterface, RouterInterface};
use Kode\AiAgent\Moe\Strategy\CapabilityAwareStrategy;
use Kode\AiAgent\Moe\Strategy\RoutingStrategyInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 默认模型路由器
 *
 * MOE 架构的核心组件：根据路由上下文、专家健康度、成本预算等，
 * 自动选择最合适的专家来处理请求。
 *
 * @package Kode\AiAgent\Moe
 *
 * @example
 * ```php
 * $router = new ModelRouter();
 * $router->registerExpert(new Expert($openaiAdapter));
 * $router->registerExpert(new Expert($deepseekAdapter));
 *
 * $response = $router->dispatch($prompt, [
 *     'capability' => 'chat',
 *     'max_cost' => 0.01,
 * ]);
 * ```
 */
final class ModelRouter implements RouterInterface
{
    /** @var array<int, ExpertInterface> */
    private array $experts = [];

    private RoutingStrategyInterface $strategy;
    private LoggerInterface $logger;
    private ModelPriceTable $priceTable;
    private TokenBudget $budget;

    /** @var array<string, array{count: int, success: int, failed: int, total_tokens: int, total_cost: float, last_used: float}> */
    private array $statistics = [];

    public function __construct(
        ?RoutingStrategyInterface $strategy = null,
        ?LoggerInterface $logger = null,
        ?ModelPriceTable $priceTable = null,
        ?TokenBudget $budget = null,
    ) {
        $this->strategy = $strategy ?? new CapabilityAwareStrategy();
        $this->logger = $logger ?? new NullLogger();
        $this->priceTable = $priceTable ?? new ModelPriceTable();
        $this->budget = $budget ?? new TokenBudget();
    }

    #[\NoDiscard]
    public function dispatch(PromptInterface $prompt, array $options = []): ResponseInterface
    {
        $context = RoutingContext::fromArray($options, $prompt->text());
        $expert = $this->select($context);

        $this->logger->debug('MOE 路由', [
            'expert' => $expert->id(),
            'platform' => $expert->platform(),
            'model' => $expert->model(),
            'strategy' => $this->strategy->name(),
            'capability' => $context->capability,
        ]);

        $startTime = microtime(true);
        try {
            $response = $expert->send($prompt, $options);
            $this->recordSuccess($expert, $response, microtime(true) - $startTime);
            return $response;
        } catch (\Throwable $e) {
            $this->recordFailure($expert, $e);
            $expert->markUnhealthy($e->getMessage());
            $this->logger->warning('专家调用失败，标记不健康', [
                'expert' => $expert->id(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    #[\NoDiscard]
    public function stream(PromptInterface $prompt, array $options = []): \Generator
    {
        $context = RoutingContext::fromArray($options + ['stream' => true], $prompt->text());
        $expert = $this->select($context);

        $this->logger->debug('MOE 流式路由', [
            'expert' => $expert->id(),
            'model' => $expert->model(),
        ]);

        yield from $expert->stream($prompt, $options);
    }

    public function registerExpert(ExpertInterface $expert): self
    {
        $this->experts[] = $expert;
        $this->logger->info('注册专家', [
            'id' => $expert->id(),
            'platform' => $expert->platform(),
            'model' => $expert->model(),
            'capabilities' => $expert->capabilities(),
        ]);
        return $this;
    }

    public function registerExperts(iterable $experts): self
    {
        foreach ($experts as $expert) {
            $this->registerExpert($expert);
        }
        return $this;
    }

    public function select(RoutingContext $context): ExpertInterface
    {
        $candidates = $this->healthyExperts();

        if ($candidates === []) {
            throw new \RuntimeException('没有可用的 AI 专家');
        }

        return $this->strategy->select($candidates, $context);
    }

    public function experts(): array
    {
        return $this->experts;
    }

    public function statistics(): array
    {
        return $this->statistics;
    }

    /**
     * 设置路由策略
     */
    public function setStrategy(RoutingStrategyInterface $strategy): self
    {
        $this->strategy = $strategy;
        return $this;
    }

    /**
     * 获取价格表
     */
    public function priceTable(): ModelPriceTable
    {
        return $this->priceTable;
    }

    /**
     * 获取 Token 预算
     */
    public function budget(): TokenBudget
    {
        return $this->budget;
    }

    /**
     * 获取健康的专家
     *
     * @return array<int, ExpertInterface>
     */
    private function healthyExperts(): array
    {
        return array_values(array_filter(
            $this->experts,
            static fn(ExpertInterface $e) => $e->isHealthy()
        ));
    }

    private function recordSuccess(ExpertInterface $expert, ResponseInterface $response, float $duration): void
    {
        $id = $expert->id();
        if (!isset($this->statistics[$id])) {
            $this->statistics[$id] = [
                'count' => 0, 'success' => 0, 'failed' => 0,
                'total_tokens' => 0, 'total_cost' => 0.0, 'last_used' => 0.0,
            ];
        }

        $usage = $response->usage();
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $cost = $this->priceTable->estimate($expert->model(), $promptTokens, $completionTokens);

        $this->statistics[$id]['count']++;
        $this->statistics[$id]['success']++;
        $this->statistics[$id]['total_tokens'] += $promptTokens + $completionTokens;
        $this->statistics[$id]['total_cost'] += $cost;
        $this->statistics[$id]['last_used'] = microtime(true);

        try {
            $this->budget->consume($promptTokens, $completionTokens, $cost);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Token 预算已耗尽', [
                'expert' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordFailure(ExpertInterface $expert, \Throwable $e): void
    {
        $id = $expert->id();
        if (!isset($this->statistics[$id])) {
            $this->statistics[$id] = [
                'count' => 0, 'success' => 0, 'failed' => 0,
                'total_tokens' => 0, 'total_cost' => 0.0, 'last_used' => 0.0,
            ];
        }

        $this->statistics[$id]['count']++;
        $this->statistics[$id]['failed']++;
    }
}
