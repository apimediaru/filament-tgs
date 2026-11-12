<?php

namespace ApiMedia\Tgs\Enums;

use Filament\Support\Contracts\HasLabel;

enum ButtonVariant: string implements HasLabel
{
    case SOLID = 'solid';
    case OUTLINE = 'outline';
    case GHOST = 'ghost';
    case NEON = 'neon';

    public function getLabel(): string
    {
        return match ($this) {
            self::SOLID => __('tgs::filament.tgs.enums.button_variant.solid'),
            self::OUTLINE => __('tgs::filament.tgs.enums.button_variant.outline'),
            self::GHOST => __('tgs::filament.tgs.enums.button_variant.ghost'),
            self::NEON => __('tgs::filament.tgs.enums.button_variant.neon'),
        };
    }
}
