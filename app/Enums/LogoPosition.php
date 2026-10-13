<?php

namespace App\Enums;

enum LogoPosition: string
{
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';

    public static function default(): self
    {
        return self::Left;
    }

    public function label(): string
    {
        return match ($this) {
            self::Left => 'Left',
            self::Center => 'Centre',
            self::Right => 'Right',
        };
    }
}
