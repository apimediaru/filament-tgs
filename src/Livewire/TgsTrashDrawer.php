<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Livewire;

use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockDeletion;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Services\HostLabelResolver;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsAuthorization;
use ApiMedia\Tgs\Support\TgsModels;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

use function abort_if;
use function is_string;
use function max;

/**
 * §Приложение А, Слайс D: корзина канваса — как и TgsBlockHistoryDrawer, СВОЙ отдельный
 * Livewire-компонент, не встроенный шаблон внутри TgsCanvas (см. TgsBlockHistoryDrawer's
 * докблок — тот же forceRender()/morph-pass регресс подстерегает любую разметку, влитую в
 * корень TgsCanvas). `#[On('open-canvas-trash')]` глобально слушает открытие, `mountAction()`
 * держит slide-over открытым как единственное состояние "открыт/закрыт".
 */
class TgsTrashDrawer extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public int $canvasId;

    #[Locked]
    public bool $readonly = false;

    public function hydrate(): void
    {
        Gate::authorize('tgs.access');
        TgsAuthorization::authorizeCanvas($this->canvas());
    }

    public function mount(int $canvasId, bool $readonly = false): void
    {
        Gate::authorize('tgs.access');
        $this->canvasId = $canvasId;
        TgsAuthorization::authorizeCanvas($this->canvas());
        $this->readonly = $readonly;
    }

    private function guardWritable(): void
    {
        abort_if($this->readonly, 403);
        TgsAuthorization::authorizeCanvas($this->canvas());
    }

    private ?Canvas $canvasCache = null;

    private function canvas(): Canvas
    {
        return $this->canvasCache ??= TgsModels::canvas()::findOrFail($this->canvasId);
    }

    /**
     * `block_deletions.canvas_id` не обязательно совпадает с `$this->canvasId` — см.
     * `CanvasPersistence::findCanvasId()`'s докблок и тот же приём в `TgsCanvas::
     * trashCanvasId()`: `HasCanvases`'s `morphMany` без зарегистрированного `Relation::
     * morphMap()` пишет `host_type` полным FQCN, а `HostDescriptor` — snake_case алиасом, так
     * что для хоста, заведённого через `canvasGroups()`, это ДВЕ разные строки `canvases`.
     * Read-only (`findCanvasId()`, не `resolveCanvasId()`) — намеренно: и getEntriesProperty(),
     * и restore()'s ownership-проверка вызывают этот метод, а ни то ни другое не должно заводить
     * теневую строку `canvases` как побочный эффект (см. `findCanvasId()`'s докблок за тем,
     * почему `firstOrCreate()` здесь был реальным багом). `null` (для хоста, для которого
     * `canvas_id` ни разу не резолвился, т.е. блок ни разу не удалялся) означает "корзина пуста",
     * без отдельной ветки на null у вызывающих — им подставляется недостижимый id.
     *
     * Резолвится через тот же путь, что и `\ApiMedia\Tgs\Support\TgsModels::canvas()::hostDescriptor()` (`morphTo`-загрузка хоста +
     * пересчёт алиаса), а не через сырые колонки — здесь, в отличие от `CanvasPersistence::
     * hostDescriptorForCanvas()`, реальная модель-хост нужна и доступна.
     */
    private function trashCanvasId(): int
    {
        return app(CanvasPersistence::class)->findCanvasId($this->canvas()->hostDescriptor()) ?? -1;
    }

    /**
     * Не мутирует — должно работать и в read-only, поэтому guardWritable() здесь нет; сам
     * компонент в read-only просто не монтируется в блейде канваса (см. tgs-canvas.blade.php),
     * так что до этого слушателя дело в readonly-режиме и не доходит, но проверка оставлена
     * как defense-in-depth на случай прямого dispatch с клиента.
     */
    #[On('open-canvas-trash')]
    public function openTrash(int $canvasId): void
    {
        if ($this->readonly || $canvasId !== $this->canvasId) {
            return;
        }

        $this->mountAction('trashAction');
    }

    /**
     * Строки корзины (Требование 6.5) — только корни удалённых поддеревьев текущего канваса.
     * `block_deletions` уже хранит ровно одну строку на операцию удаления, на корень
     * поддерева (Слайс B) — фильтровать "только корни" дополнительно не нужно.
     *
     * @return Collection<int, object{id: string, icon: string|\BackedEnum|Htmlable|null, label: string, preview: ?string, authorLabel: string, createdAt: Carbon, locationLabel: string, nestedCount: int}>
     */
    #[Computed]
    public function getEntriesProperty(): Collection
    {
        // @phpstan-ignore return.type (same as getHistoryVersionsProperty() in TgsBlockHistoryDrawer — Larastan can't carry the object-shape docblock through map()/filter()/values())
        return TgsModels::deletion()::query()
            ->where('canvas_id', $this->trashCanvasId())
            ->orderByDesc('created_at')
            ->with('actor')
            ->get()
            ->map(fn (BlockDeletion $deletion): ?object => $this->describeEntry($deletion))
            ->filter()
            ->values();
    }

    private function describeEntry(BlockDeletion $deletion): ?object
    {
        // Deleted block row itself is never physically removed at this stage (Слайс E's GC
        // is what eventually purges it) — a missing row here would only mean data corruption,
        // not a normal state; degrade the single row rather than 500ing the whole drawer.
        $block = TgsModels::block()::find($deletion->block_id);
        if ($block === null) {
            return null;
        }

        // restoreBlock() (Слайс C) never deletes the `block_deletions` row itself — only GC
        // (Слайс E) eventually purges it. A restored root's block no longer carries THIS
        // deletion's `transaction_id` (either it's alive again, or it was re-deleted later
        // under a NEW transaction_id) — filtering here, not by deleting the journal row, is
        // what makes the list "forget" a restored entry without touching the append-only log.
        if ($block->deleted_op_id !== $deletion->transaction_id) {
            return null;
        }

        $class = ContentBuilderFactory::blockClassFor($block->type);
        $meta = $class !== null
            ? TgsCanvas::describeBlock($class)
            : ['label' => $block->type, 'icon' => null];

        $preview = $block->name ?? ($class !== null ? $class::make()->blockPreview($block->data ?? [], $block) : null);

        /** @var array<string, mixed> $payload */
        $payload = $deletion->payload;
        $locationLabel = $this->originalLocationLabel($payload);

        $nestedCount = max(
            0,
            TgsModels::block()::query()->where('deleted_op_id', $deletion->transaction_id)->count() - 1,
        );

        return (object) [
            'id' => $deletion->id,
            'icon' => $meta['icon'],
            'label' => $meta['label'],
            'preview' => $preview,
            'authorLabel' => TgsModels::authorLabel($deletion->actor) ?? __('tgs::filament.tgs.canvas.trash.system_actor'),
            'createdAt' => $deletion->created_at,
            'locationLabel' => $locationLabel,
            'nestedCount' => $nestedCount,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function originalLocationLabel(array $payload): string
    {
        $type = $payload['parent_container_type'] ?? null;
        $id = $payload['parent_container_id'] ?? null;
        $slot = $payload['slot'] ?? null;

        if (! is_string($type) || ! is_string($id) || ! is_string($slot)) {
            return __('tgs::filament.tgs.canvas.trash.unknown_location');
        }

        return app(HostLabelResolver::class)->describe(new HostDescriptor($type, $id, $slot))['label'];
    }

    /**
     * §Приложение А, Слайс D: сама корзина, native Filament `->slideOver()` — тот же паттерн,
     * что historyAction() у TgsBlockHistoryDrawer. Никаких вложенных action'ов внутри —
     * "Вернуть" вызывается напрямую (restore()), как revertVersion() в истории, а не через
     * registerModalActions(), потому что восстановление не открывает СВОЙ собственный модал
     * поверх этого (в отличие от viewDiffAction там).
     */
    public function trashAction(): Action
    {
        return Action::make('trashAction')
            ->modalHeading(__('tgs::filament.tgs.canvas.trash.title'))
            ->slideOver()
            ->modalWidth(Width::Large)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('tgs::filament.tgs.canvas.trash.close'))
            ->modalContent(fn (): View => view('tgs::_trash-list', [
                'entries' => $this->getEntriesProperty(),
                'readonly' => $this->readonly,
            ]));
    }

    /**
     * Требование 5.4: восстановление, съехавшее от исходной позиции (`positionMatched ===
     * false`), должно быть НЕ молчаливым — предупреждающий toast. Точное совпадение позиции
     * (`true`) получает нейтральный success-toast (тот же стиль, что и revertVersion() у
     * TgsBlockHistoryDrawer) — решение по месту: полное молчание на успехе было бы
     * рассогласовано с остальными мутациями канваса, которые всегда подтверждают действие.
     *
     * После восстановления дровер остаётся открытым (в отличие от revertVersion(), который
     * закрывает свой) — Требование 6 ожидает, что строка просто исчезнет из списка, не что
     * вся корзина захлопнется; `getEntriesProperty()` пересчитывается на каждый рендер.
     * `tgs-canvas-changed` — сигнал TgsCanvas (см. его docblock у handleExternalCanvasChange())
     * перерендерить СВОЁ дерево, поскольку restore() мутирует состояние через СЕПАРАТНЫЙ
     * Livewire-компонент, а не сам TgsCanvas.
     */
    public function restore(string $deletionId): void
    {
        $this->guardWritable();

        $deletion = TgsModels::deletion()::query()->where('canvas_id', $this->trashCanvasId())->find($deletionId);
        if ($deletion === null) {
            return;
        }

        $result = app(CanvasPersistence::class)->restoreBlock($deletionId);
        if ($result === null) {
            return;
        }

        if ($result['positionMatched']) {
            Notification::make()
                ->title(__('tgs::filament.tgs.canvas.trash.restored'))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('tgs::filament.tgs.canvas.trash.restored_position_mismatch'))
                ->warning()
                ->send();
        }

        $this->dispatch('tgs-canvas-changed', canvasId: $this->canvasId);
    }

    public function render(): View
    {
        return view('tgs::tgs-trash-drawer');
    }
}
