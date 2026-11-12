<?php

namespace Workbench\App\Filament\Resources\TgsDemoResource\Pages;

use ApiMedia\Tgs\Support\Filament\Concerns\HandlesProvisionalCreate;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Workbench\App\Filament\Resources\TgsDemoResource;

class CreateTgsDemo extends CreateRecord
{
    use HandlesProvisionalCreate;

    protected static string $resource = TgsDemoResource::class;

    public function defaultForm(Schema $schema): Schema
    {
        $schema = parent::defaultForm($schema);
        if ($this->record !== null) {
            $schema->model($this->record);
        }

        return $schema;
    }
}
