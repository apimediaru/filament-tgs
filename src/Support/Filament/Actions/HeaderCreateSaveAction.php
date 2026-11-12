<?php

namespace ApiMedia\Tgs\Support\Filament\Actions;

use Filament\Actions\Action;

use function __;

class HeaderCreateSaveAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'create-save';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament-panels::resources/pages/create-record.form.actions.create.label'));

        $this->hiddenLabel();

        $this->submit(null);

        $this->action('create');

        $this->keyBindings(['mod+s']);

        $this->icon('bi-floppy');
    }
}
