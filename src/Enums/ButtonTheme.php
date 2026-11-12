<?php

namespace ApiMedia\Tgs\Enums;

use Filament\Support\Contracts\HasLabel;

enum ButtonTheme: string implements HasLabel
{
    case PRIMARY = 'primary';
    case SECONDARY = 'secondary';
    case NEUTRAL = 'neutral';
    case DANGER = 'danger';
    case SUCCESS = 'success';
    case WARNING = 'warning';
    case INFO = 'info';
    case DARK = 'dark';
    case LIGHT = 'light';

    public function getLabel(): string
    {
        return match ($this) {
            self::PRIMARY => __('tgs::filament.tgs.enums.button_theme.primary'),
            self::SECONDARY => __('tgs::filament.tgs.enums.button_theme.secondary'),
            self::NEUTRAL => __('tgs::filament.tgs.enums.button_theme.secondary'),
            self::DANGER => __('tgs::filament.tgs.enums.button_theme.danger'),
            self::SUCCESS => __('tgs::filament.tgs.enums.button_theme.success'),
            self::WARNING => __('tgs::filament.tgs.enums.button_theme.warning'),
            self::INFO => __('tgs::filament.tgs.enums.button_theme.info'),
            self::DARK => __('tgs::filament.tgs.enums.button_theme.dark'),
            self::LIGHT => __('tgs::filament.tgs.enums.button_theme.light'),
        };
    }
}
