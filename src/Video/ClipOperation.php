<?php

declare(strict_types=1);

namespace Kode\AiAgent\Video;

/**
 * 剪辑操作类型枚举
 */
enum ClipOperation: string
{
    case CUT = 'cut';
    case TRIM = 'trim';
    case SPLIT = 'split';
    case SPEED = 'speed';
    case REVERSE = 'reverse';
    case ROTATE = 'rotate';
    case CROP = 'crop';
    case SCALE = 'scale';
    case PAD = 'pad';
    case DELAY = 'delay';
}
