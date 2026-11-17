<?php

namespace Workbench\App\Filament\Resources\TgsDemoResource\Pages;

use ApiMedia\Tgs\Support\Filament\Concerns\HandlesProvisionalCreate;
use Filament\Resources\Pages\CreateRecord;
use Workbench\App\Filament\Resources\TgsDemoResource;

class CreateTgsDemo extends CreateRecord
{
    use HandlesProvisionalCreate;

    protected static string $resource = TgsDemoResource::class;
}
