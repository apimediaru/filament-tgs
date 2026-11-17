<?php

namespace ApiMedia\Tgs\Tests\Fixtures\CommonSettings;

use ApiMedia\Tgs\Support\TgsCommonSettings;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;

class SiteSettings extends TgsCommonSettings
{
    public static function dataClass(): string
    {
        return SiteSection::class;
    }

    public function heading(array $fields): array
    {
        return [...$fields, TextInput::make('section_note')->default('New')];
    }

    public function section(array $fields): array
    {
        return [...$fields, Group::make([TextInput::make('tone')])->statePath('section_style')];
    }
}
