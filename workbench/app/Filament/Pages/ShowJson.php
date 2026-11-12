<?php

namespace Workbench\App\Filament\Pages;

use Filament\Pages\Page;
use Workbench\App\Models\TgsDemo;

class ShowJson extends Page
{
    protected string $view = 'tgs-workbench::json';

    protected static ?string $title = 'TGS JSON';

    public function jsonContent(): array
    {
        return TgsDemo::query()->first()?->getContent() ?? [];
    }
}
