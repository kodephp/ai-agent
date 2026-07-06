<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support;

use Kode\AiAgent\Exception\InvalidResponseException;

/**
 * JSON 解析器
 *
 * 优先使用 PHP 8.3+ 的 json_validate() 进行前置校验，
 * 避免在无效 JSON 上调用 json_decode() 产生混淆错误。
 * 向后兼容：低版本 PHP 仍可用 json_decode() 兜底校验。
 *
 * @package Kode\AiAgent\Support
 */
final class JsonParser
{
    /**
     * 解析 JSON 字符串为数组
     *
     * @param string $json JSON 字符串
     * @param bool $associative 是否返回关联数组
     * @return array<string, mixed> 解析结果
     * @throws InvalidResponseException 当 JSON 无效时
     */
    public static function parseArray(string $json, bool $associative = true): array
    {
        if (!self::isValid($json)) {
            throw InvalidResponseException::jsonFailed('JSON 格式无效', ['raw' => substr($json, 0, 200)]);
        }

        $data = json_decode($json, $associative);

        if (!is_array($data)) {
            throw InvalidResponseException::jsonFailed('JSON 顶层不是对象/数组');
        }

        return $data;
    }

    /**
     * 校验 JSON 是否有效
     */
    public static function isValid(string $json): bool
    {
        if (function_exists('json_validate')) {
            return json_validate($json);
        }

        // PHP 8.2 及以下兜底
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
