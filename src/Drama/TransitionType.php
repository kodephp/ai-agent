<?php

declare(strict_types=1);

namespace Kode\AiAgent\Drama;

/**
 * 转场效果类型
 */
enum TransitionType: string
{
    case FADE = 'fade';
    case DISSOLVE = 'dissolve';
    case SLIDE_LEFT = 'slide_left';
    case SLIDE_RIGHT = 'slide_right';
    case SLIDE_UP = 'slide_up';
    case SLIDE_DOWN = 'slide_down';
    case ZOOM_IN = 'zoom_in';
    case ZOOM_OUT = 'zoom_out';
    case BLUR = 'blur';
    case CROSS_WIPE = 'cross_wipe';
    case RADIAL_BLUR = 'radial_blur';
}
