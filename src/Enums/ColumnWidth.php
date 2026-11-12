<?php

namespace ApiMedia\Tgs\Enums;

use Filament\Support\Contracts\HasLabel;

enum ColumnWidth: string implements HasLabel
{
    case ONE_QUARTER = '1/4';
    case ONE_THIRD = '1/3';
    case HALF = '1/2';
    case TWO_THIRDS = '2/3';
    case THREE_QUARTERS = '3/4';
    case FULL = 'full';
    case AUTO = 'auto';

    public function getLabel(): string
    {
        return match ($this) {
            self::ONE_QUARTER => __('tgs::filament.tgs.enums.column_width.one_quarter'),
            self::ONE_THIRD => __('tgs::filament.tgs.enums.column_width.one_third'),
            self::HALF => __('tgs::filament.tgs.enums.column_width.half'),
            self::TWO_THIRDS => __('tgs::filament.tgs.enums.column_width.two_thirds'),
            self::THREE_QUARTERS => __('tgs::filament.tgs.enums.column_width.three_quarters'),
            self::FULL => __('tgs::filament.tgs.enums.column_width.full'),
            self::AUTO => __('tgs::filament.tgs.enums.column_width.auto'),
        };
    }
}
