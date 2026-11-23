<?php

namespace ApiMedia\Tgs\Support\Filament\Concerns;

use ApiMedia\Tgs\Support\Contracts\HasProvisionalRecord;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
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

    private bool $draftFormUpdated = false;

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
        $this->restoreDraftForm();

        $this->previousUrl = url()->previous();
    }

    public function create(bool $another = false): void
    {
        $record = $this->record;
        $draftKey = $this->draftFormKey();
        if ($draftKey !== null) {
            $this->storeDraftForm($draftKey);
        }

        parent::create($another);

        if ($draftKey !== null && $record instanceof HasProvisionalRecord && $record::findProvisional($this->draft) === null) {
            Cache::forget($draftKey);
        }

        // Filament clears the record after a successful "create another".
        if ($another && $this->record === null) {
            // Replace the saved draft's history entry so Cancel can leave the create page.
            $this->js('url => window.location.replace(url)', $this->newDraftUrl());
        }
    }

    public function hasDatabaseTransactions(): bool
    {
        // A failure in a later create hook must hide the provisional record again.
        return true;
    }

    public function renderingHandlesProvisionalCreate(): void
    {
        if ($this->draftFormKey() === null) {
            return;
        }

        foreach ($this->draftFields() as $field) {
            $isText = $field instanceof TextInput || $field instanceof Textarea
                || $field instanceof RichEditor || $field instanceof MarkdownEditor;
            $field->live(debounce: $isText ? '500ms' : null);
        }
    }

    public function updatedHandlesProvisionalCreate(string $path): void
    {
        $statePath = $this->form->getStatePath();
        $this->draftFormUpdated = $this->draftFormUpdated
            || $path === $statePath || str_starts_with($path, $statePath.'.');
    }

    public function dehydrateHandlesProvisionalCreate(): void
    {
        if ($this->draftFormUpdated && ($draftKey = $this->draftFormKey()) !== null) {
            $this->storeDraftForm($draftKey);
        }
    }

    private function restoreDraftForm(): void
    {
        $draftKey = $this->draftFormKey();
        $state = $draftKey === null ? [] : Cache::get($draftKey, []);

        if ($state === []) {
            return;
        }

        // A selector such as the page template may make nested fields visible.
        uksort($state, static fn (string $left, string $right): int => substr_count($left, '.') <=> substr_count($right, '.'));
        foreach ($state as $path => $value) {
            $field = $this->form->getComponentByStatePath($path, withAbsoluteStatePath: true);
            if ($field instanceof Field && $this->isDraftField($field)) {
                $field->rawState($value);
            }
        }
    }

    private function storeDraftForm(string $draftKey): void
    {
        $state = [];
        foreach ($this->draftFields() as $field) {
            $path = $field->getStatePath();
            $value = data_get($this, $path);
            if ($this->isDraftValue($value)) {
                $state[$path] = $value;
            }
        }

        Cache::put($draftKey, $state, now()->addDay());
    }

    /** @return iterable<Field> */
    private function draftFields(): iterable
    {
        foreach ($this->form->getFlatFields(withAbsoluteKeys: true) as $field) {
            if ($this->isDraftField($field)) {
                yield $field;
            }
        }
    }

    private function isDraftField(Field $field): bool
    {
        if ($field instanceof BaseFileUpload || $field instanceof Hidden || $field->isDisabled()
            || ($field instanceof TextInput && $field->isPassword())) {
            return false;
        }

        // Counters and other decorations have schemas too, but carry no nested input.
        return ! collect($field->getChildSchemas(withHidden: true))->contains(
            static fn (Schema $schema): bool => $schema->getFlatFields(withHidden: true) !== [],
        );
    }

    private function isDraftValue(mixed $value): bool
    {
        if (! is_array($value)) {
            return $value === null || is_scalar($value);
        }

        foreach ($value as $item) {
            if (! $this->isDraftValue($item)) {
                return false;
            }
        }

        return true;
    }

    private function draftFormKey(): ?string
    {
        if ($this->draft === null || ! $this->record instanceof HasProvisionalRecord || ! $this->record->isProvisional()) {
            return null;
        }

        // Separate keys keep concurrent Livewire requests from rewriting each other's session data.
        return 'tgs:draft-form:'.hash('sha256', serialize([session()->getId(), static::class, $this->draft, $this->record->getKey()]));
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
        $schema = parent::defaultForm($schema)->live();

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
