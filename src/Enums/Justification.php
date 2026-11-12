<?php

namespace ApiMedia\Tgs\Enums;

use Filament\Support\Contracts\HasLabel;

enum Justification: string implements HasLabel
{
    case START = 'start';
    case CENTER = 'center';
    case END = 'end';
    case BETWEEN = 'between';
    case AROUND = 'around';
    case EVENELY = 'evenly';

    public function getLabel(): string
    {
        return match ($this) {
            self::START => __('tgs::filament.tgs.enums.justification.start'),
            self::CENTER => __('tgs::filament.tgs.enums.justification.center'),
            self::END => __('tgs::filament.tgs.enums.justification.end'),
            self::BETWEEN => __('tgs::filament.tgs.enums.justification.between'),
            self::AROUND => __('tgs::filament.tgs.enums.justification.around'),
            self::EVENELY => __('tgs::filament.tgs.enums.justification.evenly'),
        };
    }
}
