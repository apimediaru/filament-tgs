<?php

namespace ApiMedia\Tgs\Filament\Resources\CollectionResource\Pages;

use ApiMedia\Tgs\Filament\Resources\CollectionResource;
use ApiMedia\Tgs\Support\Filament\Actions\HeaderCreateSaveAction;
use ApiMedia\Tgs\Support\Filament\Concerns\HandlesProvisionalCreate;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;

class CreateCollection extends CreateRecord
{
    use HandlesProvisionalCreate;

    protected static string $resource = CollectionResource::class;

    /**
     * `mount()`'s `$this->form->model($this->record)` only patches the schema
     * instance built on the FIRST request — see `CreateNews::defaultForm()`
     * for the full mechanics. Re-applying the binding here makes it durable
     * across every subsequent schema rebuild, not just the initial mount.
     */
    public function defaultForm(Schema $schema): Schema
    {
        $schema = parent::defaultForm($schema);

        if ($this->record !== null) {
            $schema->model($this->record);
        }

        return $schema;
    }

    public function getHeaderActions(): array
    {
        return [
            HeaderCreateSaveAction::make(),
        ];
    }
}
