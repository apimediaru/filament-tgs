<?php

namespace ApiMedia\Tgs\Filament\Resources\CollectionResource\Pages;

use ApiMedia\Tgs\Filament\Resources\CollectionResource;
use ApiMedia\Tgs\Support\Filament\Actions\SaveAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCollection extends EditRecord
{
    protected static string $resource = CollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SaveAction::make(),
            DeleteAction::make(),
        ];
    }
}
