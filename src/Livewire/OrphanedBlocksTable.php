<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Livewire;

use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Services\HostLabelResolver;
use ApiMedia\Tgs\Support\BlockEditData;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsModels;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Отдельная страница «Осиротевшие блоки» (owner_container_type='orphan',
 * \ApiMedia\Tgs\Support\TgsModels::block()::unclaimedOwner()) — блоки, потерявшие владельца, но всё ещё живые/видимые везде, где
 * у них остался BlockPlacement. Тот же паттерн отдельного Livewire-компонента со своим
 * списком + Filament Actions, что и TgsTrashDrawer, но БЕЗ ->slideOver(): это обычная
 * полностраничная таблица, не выезжающая панель.
 */
class OrphanedBlocksTable extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** Server-owned repeater items preserve hidden values across reorder/delete. */
    #[Locked]
    public array $blockEditItems = [];

    /** Same-request payload used to fill the form before repeater UUIDs exist. */
    private array $blockEditOriginal = [];

    public function hydrate(): void
    {
        $this->mount();
    }

    public function mount(): void
    {
        Gate::authorize('tgs.access');
        Gate::authorize('tgs.manageLibrary');
    }

    /**
     * @return Collection<int, object{id: string, type: string, icon: string|\BackedEnum|Htmlable|null, label: string, preview: ?string, usageLabel: string}>
     */
    #[Computed]
    public function getEntriesProperty(): Collection
    {
        // @phpstan-ignore return.type (same shape-docblock limitation as TgsTrashDrawer::getEntriesProperty())
        return TgsModels::block()::query()
            ->where('owner_container_type', 'orphan')
            ->alive()
            ->committed()
            ->get()
            ->map(fn (Block $block): object => $this->describeEntry($block))
            ->values();
    }

    private function describeEntry(Block $block): object
    {
        $class = ContentBuilderFactory::blockClassFor($block->type);
        $meta = $class !== null
            ? TgsCanvas::describeBlock($class)
            : ['label' => $block->type, 'icon' => null];

        $preview = $block->name ?? ($class !== null ? $class::make()->blockPreview($block->data ?? [], $block) : null);

        $usageLabel = (new Collection(app(CanvasPersistence::class)->resolveUsagePlaces($block->id)))
            ->map(fn (HostDescriptor $host): string => app(HostLabelResolver::class)->describe($host)['label'])
            ->implode(', ');

        return (object) [
            'id' => $block->id,
            'type' => $block->type,
            'icon' => $meta['icon'],
            'label' => $meta['label'],
            'preview' => $preview,
            'usageLabel' => $usageLabel !== '' ? $usageLabel : __('tgs::filament.tgs.orphaned_blocks.no_usage'),
        ];
    }

    /**
     * Упрощённая версия TgsCanvas::editBlockAction() — трактует каждый осиротевший блок как
     * "root" при вызове публичной TgsCanvas::blockEditSchema() (нет read-only режима, нет
     * различия isRoot/isCollectionHost на этой странице). HostDescriptor передаётся тем же
     * синтетическим 'orphan'/'unknown' сентинелом, что и \ApiMedia\Tgs\Support\TgsModels::block()::unclaimedOwner() — только для
     * signalChanged()'s fan-out, который резолвит реальные места использования сам.
     */
    public function editBlockAction(): Action
    {
        return Action::make('editBlockAction')
            ->databaseTransaction()
            ->beforeFormValidated(function (): void {
                app(BlockEditData::class)->prepareValidation($this->getMountedActionSchema());
            })
            ->afterFormFilled(function (): void {
                $this->blockEditItems = app(BlockEditData::class)->captureItems(
                    $this->getMountedActionSchema(), $this->blockEditOriginal,
                );
                $this->blockEditOriginal = [];
            })
            ->authorize(fn (): bool => Gate::allows('tgs.manageLibrary'))
            ->fillForm(function (array $arguments): array {
                $block = TgsModels::block()::find($arguments['block'] ?? null);
                $this->blockEditOriginal = $block->data ?? [];

                return [
                    ...$this->blockEditOriginal,
                    'id' => $arguments['block'] ?? null,
                ];
            })
            ->schema(fn (array $arguments, Schema $schema): array => TgsCanvas::blockEditSchema(
                TgsModels::block()::find($arguments['block'] ?? null)?->type,
                true,
                false,
                $schema,
            ))
            ->modalHeading(function (array $arguments): string {
                $label = $arguments['label'] ?? '';

                return __('tgs::filament.tgs.canvas.block_modal.edit', ['block' => $label]);
            })
            ->action(function (array $data, array $arguments, Schema $schema): void {
                Gate::authorize('tgs.manageLibrary');
                $blockId = $arguments['block'];

                if (TgsBlock::isLockedForCurrentUser($blockId)) {
                    return;
                }

                $block = TgsModels::block()::query()->lockForUpdate()->find($blockId);
                if ($block !== null) {
                    $data = app(BlockEditData::class)->merge($block->data, $data, $schema, $this->blockEditItems);
                }

                app(CanvasPersistence::class)->editBlockData(new HostDescriptor('orphan', 'unknown', ''), $blockId, $data);

                Notification::make()
                    ->title(__('tgs::filament.tgs.canvas.notifications.block_updated'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Немедленное «Удалить везде» — без выбора вариантов unlink/force, в отличие от
     * TgsCanvas::deleteBlockAction()'s 2/3-вариантного флоу: у осиротевшего блока уже нет
     * единого владельца-хоста, так что "удалить одно размещение" здесь не имеет канонического
     * смысла — единственное осмысленное действие — снести блок целиком, во всех местах.
     */
    public function deleteBlockAction(): Action
    {
        return Action::make('deleteBlockAction')
            ->authorize(fn (): bool => Gate::allows('tgs.manageLibrary') && Gate::allows('tgs.deleteEverywhere'))
            ->visible(fn (): bool => Gate::allows('tgs.deleteEverywhere'))
            ->requiresConfirmation()
            ->color('danger')
            ->modalHeading(__('tgs::filament.tgs.orphaned_blocks.delete.heading'))
            ->modalDescription(__('tgs::filament.tgs.orphaned_blocks.delete.description'))
            ->action(function (array $arguments): void {
                Gate::authorize('tgs.manageLibrary');
                $blockId = $arguments['block'];

                if (TgsBlock::isLockedForCurrentUser($blockId)) {
                    return;
                }

                Gate::authorize('tgs.deleteEverywhere');
                app(CanvasPersistence::class)->forceDeleteEverywhere($blockId);
            });
    }

    /**
     * Даёт блоку владельца (библиотека) без клонирования и без изменения существующих
     * размещений — CanvasPersistence::adoptOrphanIntoLibrary()'s единственный вызывающий.
     */
    public function adoptIntoLibraryAction(): Action
    {
        return Action::make('adoptIntoLibraryAction')
            ->authorize(fn (): bool => Gate::allows('tgs.manageLibrary'))
            ->requiresConfirmation()
            ->modalHeading(__('tgs::filament.tgs.orphaned_blocks.adopt.heading'))
            ->modalDescription(__('tgs::filament.tgs.orphaned_blocks.adopt.description'))
            ->action(function (array $arguments): void {
                Gate::authorize('tgs.manageLibrary');
                $blockId = $arguments['block'];

                $result = app(CanvasPersistence::class)->adoptOrphanIntoLibrary($blockId);

                if ($result === null) {
                    return;
                }

                Notification::make()
                    ->title(__('tgs::filament.tgs.orphaned_blocks.adopt.success'))
                    ->success()
                    ->send();
            });
    }

    public function render(): View
    {
        return view('tgs::orphaned-blocks-table', [
            'entries' => $this->getEntriesProperty(),
        ]);
    }
}
