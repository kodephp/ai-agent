<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support\Validator;

use Kode\AiAgent\Exception\InvalidInputException;

/**
 * 输入验证器
 * 
 * 验证用户输入的安全性和有效性。
 * 
 * @package Kode\AiAgent\Support\Validator
 * 
 * @example
 * ```php
 * $validator = new InputValidator();
 * $validator->validatePrompt($userInput);
 * ```
 */
final class InputValidator
{
    private const MAX_PROMPT_LENGTH = 100000;
    private const MIN_PROMPT_LENGTH = 1;
    private const MAX_MESSAGE_COUNT = 100;

    /**
     * 验证提示词
     *
     * @param string $prompt 提示词
     * @param array $options 选项
     * @return string 验证后的提示词
     * @throws InvalidInputException 当验证失败时
     */
    public function validatePrompt(string $prompt, array $options = []): string
    {
        $maxLength = $options['max_length'] ?? self::MAX_PROMPT_LENGTH;
        $minLength = $options['min_length'] ?? self::MIN_PROMPT_LENGTH;

        // 去除首尾空白
        $prompt = trim($prompt);

        // 检查空输入
        if ($prompt === '') {
            throw new InvalidInputException('提示词不能为空');
        }

        // 检查最小长度
        if (mb_strlen($prompt) < $minLength) {
            throw new InvalidInputException("提示词长度不能少于 {$minLength} 个字符");
        }

        // 检查最大长度
        if (mb_strlen($prompt) > $maxLength) {
            throw new InvalidInputException("提示词长度不能超过 {$maxLength} 个字符");
        }

        // 检查控制字符
        if ($this->containsControlCharacters($prompt)) {
            throw new InvalidInputException('提示词包含非法控制字符');
        }

        return $prompt;
    }

    /**
     * 验证消息历史
     *
     * @param array $messages 消息列表
     * @return array 验证后的消息列表
     * @throws InvalidInputException 当验证失败时
     */
    public function validateMessages(array $messages): array
    {
        if (count($messages) > self::MAX_MESSAGE_COUNT) {
            throw new InvalidInputException("消息数量不能超过 " . self::MAX_MESSAGE_COUNT);
        }

        $validRoles = ['system', 'user', 'assistant', 'tool'];

        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                throw new InvalidInputException("消息 {$index} 格式无效");
            }

            if (!isset($message['role'])) {
                throw new InvalidInputException("消息 {$index} 缺少 role 字段");
            }

            if (!in_array($message['role'], $validRoles, true)) {
                throw new InvalidInputException("消息 {$index} 的 role 无效: {$message['role']}");
            }

            if (!isset($message['content']) && $message['role'] !== 'tool') {
                throw new InvalidInputException("消息 {$index} 缺少 content 字段");
            }
        }

        return $messages;
    }

    /**
     * 验证工具参数
     *
     * @param string $name 工具名称
     * @param array $arguments 参数
     * @return array 验证后的参数
     * @throws InvalidInputException 当验证失败时
     */
    public function validateToolCall(string $name, array $arguments): array
    {
        // 验证工具名称
        if (empty($name)) {
            throw new InvalidInputException('工具名称不能为空');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidInputException("工具名称格式无效: {$name}");
        }

        // 验证参数
        foreach ($arguments as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidInputException('工具参数键必须是字符串');
            }

            // 递归验证嵌套数组
            if (is_array($value)) {
                $this->validateArrayDepth($value, 5, "工具参数 {$key}");
            }
        }

        return $arguments;
    }

    /**
     * 验证配置选项
     *
     * @param array $options 配置选项
     * @return array 验证后的选项
     * @throws InvalidInputException 当验证失败时
     */
    public function validateOptions(array $options): array
    {
        // 验证 temperature
        if (isset($options['temperature'])) {
            $temp = $options['temperature'];
            if (!is_numeric($temp) || $temp < 0 || $temp > 2) {
                throw new InvalidInputException('temperature 必须在 0-2 之间');
            }
        }

        // 验证 max_tokens
        if (isset($options['max_tokens'])) {
            $maxTokens = $options['max_tokens'];
            if (!is_int($maxTokens) || $maxTokens < 1 || $maxTokens > 1000000) {
                throw new InvalidInputException('max_tokens 必须在 1-1000000 之间');
            }
        }

        // 验证 top_p
        if (isset($options['top_p'])) {
            $topP = $options['top_p'];
            if (!is_numeric($topP) || $topP < 0 || $topP > 1) {
                throw new InvalidInputException('top_p 必须在 0-1 之间');
            }
        }

        return $options;
    }

    /**
     * 验证 API Key 格式
     *
     * @param string $apiKey API Key
     * @return bool 是否有效
     */
    public function isValidApiKey(string $apiKey): bool
    {
        if (strlen($apiKey) < 16) {
            return false;
        }

        // 检查是否包含空白字符
        if (preg_match('/\s/', $apiKey)) {
            return false;
        }

        return true;
    }

    /**
     * 检查是否包含控制字符
     */
    private function containsControlCharacters(string $text): bool
    {
        // 允许的空白字符：空格、制表符、换行符、回车符
        $allowedPattern = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';
        
        return preg_match($allowedPattern, $text) === 1;
    }

    /**
     * 验证数组深度
     */
    private function validateArrayDepth(array $array, int $maxDepth, string $context): void
    {
        if ($maxDepth <= 0) {
            throw new InvalidInputException("{$context} 嵌套层级过深");
        }

        foreach ($array as $value) {
            if (is_array($value)) {
                $this->validateArrayDepth($value, $maxDepth - 1, $context);
            }
        }
    }
}
