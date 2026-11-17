<?php

namespace ApiMedia\Tgs\Filament\Resources\CollectionResource\Pages;

use ApiMedia\Tgs\Filament\Resources\CollectionResource;
use ApiMedia\Tgs\Support\Filament\Actions\HeaderCreateSaveAction;
use ApiMedia\Tgs\Support\Filament\Concerns\HandlesProvisionalCreate;
use Filament\Resources\Pages\CreateRecord;

class CreateCollection extends CreateRecord
{
    use HandlesProvisionalCreate;

    protected static string $resource = CollectionResource::class;

    public function getHeaderActions(): array
    {
        return [
            HeaderCreateSaveAction::make(),
        ];
    }
}
