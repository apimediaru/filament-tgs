<?php

namespace ApiMedia\Tgs\Support\Filament\Concerns;

use ApiMedia\Tgs\Support\Contracts\HasProvisionalRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

use function abort_unless;
use function is_string;
use function request;
use function url;

/**
 * Провизорный create-flow с идентичностью «на вкладку» (design: provisional-per-tab-identity).
 * Токен ?draft=<ulid> в URL ключует запись: F5 (тот же токен) держит её, свежий заход
 * (нет токена → редирект на новый) даёт чистую. Резолв — через session() в provisional(),
 * токен сам по себе не capability.
 */
trait HandlesProvisionalCreate
{
    /**
     * Токен черновика из ?draft= — в update-запросах Livewire query-строки нет,
     * поэтому храним его в компоненте; Locked — клиент не может его подменить.
     */
    #[Locked]
    public ?string $draft = null;

    public function mount(): void
    {
        $this->authorizeAccess();

        $draft = request()->query('draft');

        if (! is_string($draft) || $draft === '') {
            $this->redirect($this->newDraftUrl());

            return;
        }

        $this->draft = $draft;

        /** @var class-string<Model&HasProvisionalRecord> $modelClass */
        $modelClass = $this->getModel();
        $this->record = $modelClass::provisional($draft);
        $this->form->model($this->record);

        $this->fillForm();

        $this->previousUrl = url()->previous();
    }

    public function create(bool $another = false): void
    {
        parent::create($another);

        // Filament clears the record after a successful "create another".
        if ($another && $this->record === null) {
            // Replace the saved draft's history entry so Cancel can leave the create page.
            $this->js('url => window.location.replace(url)', $this->newDraftUrl());
        }
    }

    private function newDraftUrl(): string
    {
        return static::getResource()::getUrl('create', ['draft' => (string) Str::ulid()]);
    }

    /**
     * Filament перечитывает $record каждым Livewire-запросом через запрос ресурса, а
     * глобальный скоуп `withoutProvisional` черновик оттуда прячет (→ 404). Черновик
     * перечитываем сами — только тот, что записан в сессии под токеном этой вкладки.
     */
    protected function resolveRecordFromLivewire(Model $model): Model
    {
        if (! ($model instanceof HasProvisionalRecord) || ! $model->isProvisional()) {
            return parent::resolveRecordFromLivewire($model);
        }

        $record = $model::findProvisional($this->draft);

        abort_unless($record !== null && (string) $record->getKey() === (string) $model->getKey(), 404);

        return $record;
    }

    public function defaultForm(Schema $schema): Schema
    {
        $schema = parent::defaultForm($schema);

        // Filament rebuilds the schema on Livewire updates.
        if ($this->record !== null) {
            $schema->model($this->record);
        }

        return $schema;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $this->record->fill($data);
        $this->record->save();

        return $this->record;
    }
}
