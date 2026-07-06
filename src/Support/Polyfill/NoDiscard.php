<?php

declare(strict_types=1);

/**
 * NoDiscard 属性多版本兼容
 *
 * PHP 8.5 内置 #[\NoDiscard] 属性，用于提示调用方必须使用返回值。
 * 在 PHP 8.3/8.4 环境下，本 polyfill 提供同名的空属性类，使项目代码可以
 * 统一使用 #[\NoDiscard] 而不触发类不存在错误。
 *
 * PHP 8.5+ 时该文件不会重复定义内置类。
 */

if (\PHP_VERSION_ID < 80500 && !class_exists(\NoDiscard::class, false)) {
    #[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
    final class NoDiscard {}
}
