<?php

namespace Workbench\App\Filament\Pages;

use Filament\Pages\Page;

class DemoGuide extends Page
{
    protected string $view = 'tgs-workbench::guide';

    protected static ?string $title = 'Как проверить ТГС';

    protected static ?int $navigationSort = -10;
}
