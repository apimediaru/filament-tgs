<?php

namespace ApiMedia\Tgs\Enums;

use Filament\Support\Contracts\HasLabel;

enum BlockLockMode: string implements HasLabel
{
    case Surface = 'surface';
    case Cascade = 'cascade';

    public function getLabel(): string
    {
        return match ($this) {
            self::Surface => __('tgs::filament.tgs.enums.lock_mode.surface'),
            self::Cascade => __('tgs::filament.tgs.enums.lock_mode.cascade'),
        };
    }
}
