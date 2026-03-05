<?php

declare(strict_types=1);

namespace Kode\AiAgent\Tool;

use Kode\AiAgent\Attribute\Tool;
use Kode\Attributes\Reader;
use ReflectionClass;
use ReflectionMethod;

/**
 * 工具注册表
 * 
 * 管理和执行 AI 可调用的工具函数。
 * 使用 kode/attributes 进行注解解析。
 * 
 * @package Kode\AiAgent\Tool
 * 
 * @example
 * ```php
 * $registry = new ToolRegistry();
 * 
 * // 注册工具
 * $registry->register('calculator', '执行数学计算', function (int $a, int $b): int {
 *     return $a + $b;
 * });
 * 
 * // 从类自动注册工具
 * $registry->registerFromClass(new MyTools());
 * 
 * // 执行工具
 * $result = $registry->execute('calculator', ['a' => 1, 'b' => 2]);
 * ```
 */
final class ToolRegistry implements \Countable
{
    private array $tools = [];
    private Reader $reader;

    public function __construct(?Reader $reader = null)
    {
        $this->reader = $reader ?? new Reader();
    }

    /**
     * 注册工具
     */
    public function register(
        string $name,
        string $description,
        callable $handler,
        array $parameters = []
    ): static {
        $this->tools[$name] = [
            'name' => $name,
            'description' => $description,
            'handler' => $handler,
            'parameters' => $parameters ?: $this->extractParameters($handler),
        ];

        return $this;
    }

    /**
     * 从类自动注册工具
     *
     * 使用 kode/attributes 解析 #[Tool] 注解
     */
    public function registerFromClass(object $class): static
    {
        $reflection = new ReflectionClass($class);
        $className = $reflection->getName();

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->getName();
            $metaList = $this->reader->getMethodAttrs($className, $methodName);
            
            if ($metaList->has(Tool::class)) {
                $meta = $metaList->get(Tool::class);
                if ($meta !== null) {
                    $tool = $meta->getInstance();
                    $this->register(
                        $tool->name,
                        $tool->description,
                        $method->getClosure($class),
                        $tool->parameters
                    );
                }
            }
        }

        return $this;
    }

    /**
     * 执行工具
     */
    public function execute(string $name, array $arguments): mixed
    {
        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("工具不存在: {$name}");
        }

        $tool = $this->tools[$name];
        $handler = $tool['handler'];

        if ($this->isAssociativeArray($arguments)) {
            $orderedArgs = [];
            foreach ($tool['parameters'] as $paramName) {
                $orderedArgs[] = $arguments[$paramName] ?? null;
            }
            return $handler(...$orderedArgs);
        }

        return $handler(...$arguments);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?array
    {
        return $this->tools[$name] ?? null;
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function toOpenAIFormat(): array
    {
        $functions = [];

        foreach ($this->tools as $name => $tool) {
            $functions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $tool['description'],
                    'parameters' => $this->buildParametersSchema($tool['parameters']),
                ],
            ];
        }

        return $functions;
    }

    public function remove(string $name): static
    {
        unset($this->tools[$name]);
        return $this;
    }

    public function clear(): static
    {
        $this->tools = [];
        return $this;
    }

    /**
     * 获取工具数量
     */
    public function count(): int
    {
        return count($this->tools);
    }

    private function extractParameters(callable $handler): array
    {
        if ($handler instanceof \Closure) {
            $reflection = new \ReflectionFunction($handler);
        } elseif (is_array($handler)) {
            $reflection = new ReflectionMethod($handler[0], $handler[1]);
        } else {
            return [];
        }
        
        $parameters = [];
        foreach ($reflection->getParameters() as $param) {
            $parameters[] = $param->getName();
        }

        return $parameters;
    }

    private function buildParametersSchema(array $parameters): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => $parameters,
        ];

        foreach ($parameters as $param) {
            $schema['properties'][$param] = [
                'type' => 'string',
                'description' => $param,
            ];
        }

        return $schema;
    }

    private function isAssociativeArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
