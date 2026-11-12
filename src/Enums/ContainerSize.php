<?php

namespace ApiMedia\Tgs\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContainerSize: string implements HasLabel
{
    case SMALL = 'sm';
    case MEDIUM = 'md';
    case LARGE = 'lg';
    case FULL = 'full';
    case FLUID = 'fluid';

    public function getLabel(): string
    {
        return match ($this) {
            self::SMALL => __('tgs::filament.tgs.enums.container_size.small'),
            self::MEDIUM => __('tgs::filament.tgs.enums.container_size.medium'),
            self::LARGE => __('tgs::filament.tgs.enums.container_size.large'),
            self::FULL => __('tgs::filament.tgs.enums.container_size.full'),
            self::FLUID => __('tgs::filament.tgs.enums.container_size.fluid'),
        };
    }
}
