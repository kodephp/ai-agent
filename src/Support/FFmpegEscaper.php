<?php

declare(strict_types=1);

namespace Kode\AiAgent\Support;

/**
 * ffmpeg 滤镜参数转义与白名单工具
 *
 * 解决两类注入面：
 * 1. shell 层：拼进 exec/proc_open 的参数一律再套 escapeshellarg()；
 *    禁止把 escapeshellarg() 的产物塞回双引号包裹的命令片段（双引号会
 *    让参数内的 `"` 提前闭合引号，形成命令注入）。
 * 2. 滤镜图层：filtergraph 以 `,` `;` `:` 为分隔符，参数值必须用
 *    ffmpeg 单引号语法（quote()）包裹，或用白名单（color()）过滤，
 *    否则值内的分隔符会被解释为新滤镜/新选项。
 *
 * @package Kode\AiAgent\Support
 */
final class FFmpegEscaper
{
    private const COLOR_PATTERN = '/^[a-zA-Z0-9#x@.]+$/';

    /**
     * 以 ffmpeg 单引号语法引用一个滤镜参数值
     *
     * 覆盖滤镜图与滤镜选项两级解析（av_get_token 语义）：
     * 反斜杠先加倍，单引号转义为 \'，整体再用单引号包裹。
     * 该字符串随后可安全地交给 escapeshellarg() 进入 shell。
     */
    public static function quote(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace("'", "\\'", $value);

        return "'" . $value . "'";
    }

    /**
     * 颜色值白名单校验
     *
     * 允许：命名色（white）、#RRGGBB[AA]、0xRRGGBB[AA]、white@0.5 等；
     * 其余（引号、分隔符、反斜杠等）一律回落到 $default，杜绝滤镜图注入。
     */
    public static function color(string $value, string $default = 'black'): string
    {
        if ($value === '' || preg_match(self::COLOR_PATTERN, $value) !== 1) {
            return $default;
        }

        return $value;
    }
}
