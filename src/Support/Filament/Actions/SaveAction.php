<?php

namespace ApiMedia\Tgs\Support\Filament\Actions;

use Filament\Actions\Action;

use function __;

class SaveAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'save';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'));

        $this->hiddenLabel();

        $this->submit(null);

        $this->action('save');

        $this->keyBindings(['mod+s']);

        $this->icon('bi-floppy');
    }
}
