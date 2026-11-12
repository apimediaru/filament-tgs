<?php

namespace ApiMedia\Tgs\Enums;

use Filament\Support\Contracts\HasLabel;

enum SectionSize: string implements HasLabel
{
    case ONE_QUARTER = '1/4';
    case HALF = '1/2';
    case THREE_QUARTERS = '3/4';
    case FULL = 'full';

    public function getLabel(): string
    {
        return match ($this) {
            self::ONE_QUARTER => __('tgs::filament.tgs.enums.section_size.one_quarter'),
            self::HALF => __('tgs::filament.tgs.enums.section_size.half'),
            self::THREE_QUARTERS => __('tgs::filament.tgs.enums.section_size.three_quarters'),
            self::FULL => __('tgs::filament.tgs.enums.section_size.full'),
        };
    }
}
