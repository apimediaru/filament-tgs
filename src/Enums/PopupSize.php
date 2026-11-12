<?php

namespace ApiMedia\Tgs\Enums;

use Filament\Support\Contracts\HasLabel;

enum PopupSize: string implements HasLabel
{
    case SMALL = 'sm';
    case MEDIUM = 'md';
    case LARGE = 'lg';
    case FULL = 'full';

    public function getLabel(): string
    {
        return match ($this) {
            self::SMALL => __('tgs::filament.tgs.enums.popup_size.small'),
            self::MEDIUM => __('tgs::filament.tgs.enums.popup_size.medium'),
            self::LARGE => __('tgs::filament.tgs.enums.popup_size.large'),
            self::FULL => __('tgs::filament.tgs.enums.popup_size.full'),
        };
    }
}
