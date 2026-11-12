<?php

namespace ApiMedia\Tgs\Enums;

use Filament\Support\Contracts\HasLabel;

enum Position: string implements HasLabel
{
    case LEFT = 'left';
    case CENTER = 'center';
    case RIGHT = 'right';

    public function getLabel(): string
    {
        return match ($this) {
            self::LEFT => __('tgs::filament.tgs.enums.position.left'),
            self::CENTER => __('tgs::filament.tgs.enums.position.center'),
            self::RIGHT => __('tgs::filament.tgs.enums.position.right'),
        };
    }
}
