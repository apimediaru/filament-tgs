<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Livewire;

use ApiMedia\Tgs\Enums\TgsBlockLayout;
use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Models\Collection;
use ApiMedia\Tgs\Services\BlockChildPolicy;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Services\CanvasTreeReader;
use ApiMedia\Tgs\Services\HostLabelResolver;
use ApiMedia\Tgs\Services\TgsInsertionPolicy;
use ApiMedia\Tgs\Support\BlockEditData;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsAuthorization;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsBlockRegistry;
use ApiMedia\Tgs\Support\TgsModels;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

use function abort_if;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function trans_choice;
use function usort;

/**
 * Livewire-канвас конструктора блоков (ADR-019, plan B2): read-only рендер дерева через
 * CanvasTreeReader + мутации (add/delete/reorder/edit/duplicate), каждая из которых
 * помечает $savedAt для индикатора автосейва (Task 9).
 */
class TgsCanvas extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions {
        unmountAction as private traitUnmountAction;
    }
    use InteractsWithSchemas;

    /** Server-owned repeater items preserve hidden values across reorder/delete. */
    #[Locked]
    public array $blockEditItems = [];

    /** Same-request payload used to fill the form before repeater UUIDs exist. */
    private array $blockEditOriginal = [];

    #[Locked]
    public int $canvasId;

    /** Trusted schema state: Locked rejects client-side updates; each mutation also checks Gate. */
    #[Locked]
    public bool $readonly = false;

    /** Метка автосейва (Task 9, plan B2): "ЧЧ:ММ" последней успешной мутации, null до первой. */
    public ?string $savedAt = null;

    /**
     * Пикер-дровер (plan 2b) — цель вставки (корень = parent null). "Открыт" теперь означает
     * insertBlockAction() смонтирован (native ->slideOver(), см. openPicker()/closePicker()) —
     * больше не отдельный bool.
     */
    public ?string $pickerParentId = null;

    public ?string $pickerSlot = null;

    public ?int $pickerPosition = null;

    /** Активная вкладка дровера вставки (срез 2a-2): 'new' — галерея типов, 'library' — переиспользуемые блоки. */
    public string $pickerTab = 'new';

    /**
     * ADR-020 draft-поток: id черновика, чья модалка сейчас открыта (устанавливается
     * addRootBlock/addChildBlock/quickAddChild при создании неразмещённого черновика,
     * обнуляется при успешном commitDraft() внутри editBlockAction). null означает либо
     * "модалка не открыта", либо "открыта для правки уже committed блока" — в обоих
     * случаях unmountAction() не должен ничего удалять.
     */
    #[Locked]
    public ?string $draftBlockId = null;

    /** Destination chosen by the server before a draft has a placement. */
    #[Locked]
    public array $draftPlacement = [];

    public function hydrate(): void
    {
        Gate::authorize('tgs.access');
        if (! $this->isCanvasMissing()) {
            TgsAuthorization::authorizeCanvas($this->canvas());
        }
    }

    public function mount(int $canvasId): void
    {
        Gate::authorize('tgs.access');
        $this->canvasId = $canvasId;
        if (! $this->isCanvasMissing()) {
            TgsAuthorization::authorizeCanvas($this->canvas());
        }
    }

    /**
     * Человекочитаемая подпись слота для канваса (2b polish, косметика): переводит ключ слота
     * (left/right/content) через filament.tgs.slots.*. Данные не трогаются — ключ в БД остаётся
     * сырым. Нет перевода → возвращаем сам ключ (graceful, для будущих слотов).
     */
    public function slotLabel(string $slot): string
    {
        $key = "tgs::filament.tgs.slots.{$slot}";
        $label = __($key);

        return $label === $key ? $slot : $label;
    }

    /** @return list<mixed> */
    #[Computed]
    public function getTreeProperty(): array
    {
        return app(CanvasTreeReader::class)->read($this->host());
    }

    /**
     * `block_deletions.canvas_id` не обязательно совпадает с `$this->canvasId` (id строки
     * `canvases`, которую резолвит `\ApiMedia\Tgs\Support\TgsModels::canvas()::findOrFail()` для МОНТИРОВАНИЯ этого компонента):
     * см. `CanvasPersistence::findCanvasId()`'s докблок — `HasCanvases`'s `morphMany` без
     * зарегистрированного `Relation::morphMap()` пишет `host_type` полным FQCN, а
     * `HostDescriptor` всегда использует snake_case алиас, так что для любого хоста, заведённого
     * через `canvasGroups()`, это ДВЕ разные строки `canvases`. Read-only (`->value('id')`, не
     * `firstOrCreate()`) — это #[Computed], пересчитывается на каждый рендер, а рендер не должен
     * иметь побочных эффектов записи (заводить теневую строку `canvases» на каждый просмотр —
     * именно так и был найден этот баг, см. `findCanvasId()`'s докблок). `null` (для хоста, для
     * которого никогда не резолвился `canvas_id`, т.е. ни разу не удалялся блок) означает "корзина
     * пуста" — подставляется недостижимый id, а не отдельная ветка на null.
     */
    private function trashCanvasId(): ?int
    {
        return app(CanvasPersistence::class)->findCanvasId($this->host());
    }

    /**
     * Счётчик «Корзина (N)» в шапке (Этап 4, Слайс D). `block_deletions` — append-only журнал:
     * `restoreBlock()` НЕ удаляет строку при восстановлении, только снимает `deleted_op_id` с
     * блока (запись физически чистит только GC, Слайс E, после retention). Поэтому простой
     * `count()` строк для канваса переучитывал бы уже восстановленные записи как «всё ещё в
     * корзине» — нужен тот же фильтр «жив ли ещё этот `transaction_id` у блока», что и в
     * `TgsTrashDrawer::describeEntry()`, иначе счётчик и сам список корзины разойдутся.
     */
    #[Computed]
    public function getTrashCountProperty(): int
    {
        $canvasId = $this->trashCanvasId();

        if ($canvasId === null) {
            return 0;
        }

        return TgsModels::deletion()::query()
            ->where('canvas_id', $canvasId)
            ->whereHas('block', fn ($query) => $query->whereColumn('deleted_op_id', 'tgs_block_deletions.transaction_id'))
            ->count();
    }

    /**
     * Этап 4, Слайс D: TgsTrashDrawer — ОТДЕЛЬНЫЙ Livewire-компонент (та же причина, что и у
     * TgsBlockHistoryDrawer, см. его докблок), поэтому его restore() не может просто
     * мутировать состояние ЭТОГО компонента напрямую. Дешёвый no-op-слушатель здесь — весь
     * необходимый мост: сам факт вызова метода через Livewire-событие обязывает Livewire
     * заново отрендерить этот компонент, а #[Computed] tree/trashCount пересчитываются с нуля
     * на каждый новый запрос — так что тело метода намеренно пустое, кроме проверки
     * принадлежности этому канвасу.
     */
    #[On('tgs-canvas-changed')]
    public function handleExternalCanvasChange(int $canvasId): void
    {
        if ($canvasId !== $this->canvasId) {
            return;
        }
    }

    private ?Canvas $canvasCache = null;

    private function canvas(): Canvas
    {
        return $this->canvasCache ??= TgsModels::canvas()::findOrFail($this->canvasId);
    }

    /**
     * Hotfix-срез 1 (Q1б): `canvasId` может указывать на удалённую строку `canvases` (например,
     * пункт FAQ сохранён после того, как GC снёс его embedded-канвас). Вместо 404 на всю
     * страницу-владельца render() показывает плашку «Канвас не найден», а guardWritable()
     * отказывает любой мутации — редактор может открыть страницу и удалить битый пункт.
     */
    private function isCanvasMissing(): bool
    {
        return $this->canvasCache === null && ! TgsModels::canvas()::query()->whereKey($this->canvasId)->exists();
    }

    /**
     * Public (Слайс E): the blade needs it to stamp the root sortable container's
     * `data-container-*`/`data-slot` attributes, read back by `crossSlotMove()` when a block is
     * dropped into a different slot's list.
     */
    public function host(): HostDescriptor
    {
        return $this->canvas()->hostDescriptor();
    }

    /**
     * Единый резолвер library-хоста (Этап 1 «канвасы как сущность», Слайс C): библиотека
     * переиспользуемых блоков коренится на named-kind канвасе (ApiMedia\Tgs\Enums\TgsNamedCanvas::
     * BlockLibrary), заменившем Virtual-хост BLOCK_LIBRARY.
     */
    private function libraryHost(): HostDescriptor
    {
        return TgsModels::canvas()::named(TgsNamedCanvas::BlockLibrary)->hostDescriptor();
    }

    /**
     * Для blade (_node.blade.php): скрывает «Добавить в библиотеку» на самом канвасе
     * библиотеки — блок и так уже в библиотеке, дублировать его в неё же незачем.
     */
    public function isLibraryHost(): bool
    {
        return $this->host() == $this->libraryHost();
    }

    /**
     * Task 5 (block collections): true когда смонтированная группа принадлежит модели
     * `Collection` — в отличие от isLibraryHost() (сравнение с ОДНИМ конкретным хостом),
     * коллекций много, поэтому здесь проверяется тип groupable-модели, а не равенство
     * HostDescriptor (у каждой коллекции свой containerId).
     */
    public function isCollectionHost(): bool
    {
        return $this->canvas()->host instanceof Collection;
    }

    /**
     * editBlockAction()'s `_name` field (schema + write path) is only offered when: on a
     * collection canvas, for any block — or on the library canvas, but ONLY for a root block
     * (2026-07-23). A block nested inside a composite library block isn't independently
     * referenceable via "Из библиотеки" (getLibraryBlocksProperty()'s root-only definition of
     * a "library block"), so naming it has nothing to attach to.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function canNameBlockOnEdit(array $arguments): bool
    {
        if ($this->isCollectionHost()) {
            return true;
        }

        return $this->isLibraryHost() && $this->isRootBlockOnEdit($arguments);
    }

    private function isRootBlockOnEdit(array $arguments): bool
    {
        if (! ($arguments['isRoot'] ?? false)) {
            return false;
        }
        $host = $this->host();
        $blockId = $arguments['block'] ?? null;
        if ($blockId !== null && $blockId === $this->draftBlockId) {
            return ($this->draftPlacement['containerType'] ?? null) === $host->containerType
                && ($this->draftPlacement['containerId'] ?? null) === $host->containerId
                && ($this->draftPlacement['slot'] ?? null) === $host->slot;
        }

        return TgsModels::placement()::query()->where('block_id', $blockId)
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)->exists();
    }

    public function addRootBlock(string $type, ?int $position = null): void
    {
        $this->guardWritable();

        $host = $this->host();
        app(TgsInsertionPolicy::class)->ensureDestination($type, $host->containerType, $host->containerId, $host->slot, $host);

        if ($this->blockDeclaresSlots($type)) {
            // Контейнер: содержимое живёт в дочерних слотах, пустая модалка была бы шумом —
            // размещаем немедленно (committed), как и раньше.
            app(CanvasPersistence::class)->addBlock(
                $host, $host->containerType, $host->containerId, $host->slot, $type, [], $position,
            );
            $this->markSaved();
            $this->notifyBlockCreated();

            return;
        }

        // Лист (ADR-020): черновик без placement, размещение случится в commitDraft() при
        // сабмите модалки — цель размещения едет вместе с action-аргументами.
        $draft = app(CanvasPersistence::class)->createDraftBlock($host, $type);
        $this->draftBlockId = $draft->id;
        $this->openDraftEditor($draft->id, $type, isRoot: true, containerType: $host->containerType, containerId: $host->containerId, slot: $host->slot, position: $position);
    }

    public function addChildBlock(string $parentBlockId, string $slot, string $type, ?int $position = null): void
    {
        $this->guardWritable();

        if (! $this->blockBelongsToHost($parentBlockId) || $this->refuseLockedParent($parentBlockId)) {
            return;
        }

        app(TgsInsertionPolicy::class)->ensureDestination($type, 'block', $parentBlockId, $slot, $this->host());

        if ($this->blockDeclaresSlots($type)) {
            app(CanvasPersistence::class)->addBlock(
                $this->host(), 'block', $parentBlockId, $slot, $type, [], $position,
            );
            $this->markSaved();
            $this->notifyBlockCreated();

            return;
        }

        $draft = app(CanvasPersistence::class)->createDraftBlock($this->host(), $type);
        $this->draftBlockId = $draft->id;
        $this->openDraftEditor($draft->id, $type, isRoot: false, containerType: 'block', containerId: $parentBlockId, slot: $slot, position: $position);
    }

    /** Единственный допустимый тип слота (для быстрого добавления без дровера), иначе null. */
    public function slotSingleChild(?string $parentType, ?string $slot, ?string $parentId = null): ?string
    {
        if ($parentType === null || $slot === null) {
            return null;
        }

        $containerClass = ContentBuilderFactory::blockClassFor($parentType);
        if ($containerClass === null) {
            return null;
        }

        $allowed = app(BlockChildPolicy::class)->allowedChildrenFor($containerClass::make(), $slot);

        return count($allowed) === 1
            && app(TgsInsertionPolicy::class)->allows($allowed[0], $parentType, $slot, $this->host(), $parentId)
                ? $allowed[0] : null;
    }

    /** Быстрое добавление в слот с единственным типом: создаём и сразу открываем редактор. */
    public function quickAddChild(string $parentBlockId, string $slot): void
    {
        $this->guardWritable();

        if (! $this->blockBelongsToHost($parentBlockId) || $this->refuseLockedParent($parentBlockId)) {
            return;
        }

        $parent = TgsModels::block()::find($parentBlockId);
        $type = $this->slotSingleChild($parent?->type, $slot, $parentBlockId);
        if ($type === null) {
            return;
        }

        // Всегда draft (ADR-020), даже для item-блоков в единственном слоте: несёт заголовок,
        // требует ввода в модалке — как и любой лист, коммитится только по сабмиту.
        $draft = app(CanvasPersistence::class)->createDraftBlock($this->host(), $type);
        $this->draftBlockId = $draft->id;
        $this->openDraftEditor($draft->id, $type, isRoot: false, containerType: 'block', containerId: $parentBlockId, slot: $slot, position: null);
    }

    /**
     * Метка кнопки добавления в слот: add-метка единственного типа, иначе generic —
     * с именем слота только когда у блока 2+ слотов (при одном слоте вложенность и так
     * видна отступом, подпись слота избыточна — см. _node.blade.php).
     */
    public function slotAddLabel(?string $parentType, string $slot, ?string $parentId = null): string
    {
        $single = $this->slotSingleChild($parentType, $slot, $parentId);
        if ($single !== null) {
            $class = ContentBuilderFactory::blockClassFor($single);
            $label = $class !== null ? $class::make()->blockAddLabel() : null;
            if ($label !== null) {
                return (string) $label;
            }
        }

        $parentClass = $parentType !== null ? ContentBuilderFactory::blockClassFor($parentType) : null;
        $slotCount = $parentClass !== null ? count($parentClass::make()->blockSlots()) : 0;

        return $slotCount > 1
            ? __('tgs::filament.tgs.canvas.add_block_to_slot', ['slot' => $this->slotLabel($slot)])
            : __('tgs::filament.tgs.canvas.add_block');
    }

    /**
     * UX (plan 2b) + ADR-020 draft-поток: сразу после создания черновика ЛИСТОВОГО блока
     * (без слотов) открываем модалку правки, неся в аргументах и `block` (для fillForm), и
     * placement-цель (`containerType`/`containerId`/`slot`/`position`), которую
     * editBlockAction.action() передаст в commitDraft() при сабмите. Контейнеры (blockSlots()
     * непустой) в этот метод не попадают — они коммитятся немедленно в addRootBlock/
     * addChildBlock и просто появляются, без модалки. Дублирование намеренно НЕ
     * авто-открывается (идёт через CanvasPersistence напрямую).
     */
    private function openDraftEditor(string $blockId, string $type, bool $isRoot, string $containerType, string $containerId, string $slot, ?int $position): void
    {
        $this->draftPlacement = compact('containerType', 'containerId', 'slot', 'position');
        $this->mountAction('editBlockAction', [
            'block' => $blockId,
            'type' => $type,
            'isRoot' => $isRoot,
            'containerType' => $containerType,
            'containerId' => $containerId,
            'slot' => $slot,
            'position' => $position,
        ]);

        // Filament's partial-render optimization (PartialsComponentHook) assumes a freshly
        // mounted action is the ONLY thing that changed this request and sends back just the
        // modal partial, skipping the full component re-render. Here the same request also
        // added a block to the tree, so without forcing a full render the canvas behind the
        // modal would stay stale (old tree/savedAt) until an unrelated later mutation forces one.
        $this->forceRender();
    }

    private function blockDeclaresSlots(string $type): bool
    {
        $class = ContentBuilderFactory::blockClassFor($type);

        return $class !== null && $class::make()->blockSlots() !== [];
    }

    /**
     * Ownership guard (plan B2 fix): mirrors CanvasPersistence::deleteBlock/editBlockData's own
     * inline check (same owner_container_type/owner_container_id/'orphan' semantics), applied
     * here because duplicate/addChild/reorderChild take a client-supplied block id that
     * CanvasPersistence itself never validates against the mounted host.
     */
    private function blockBelongsToHost(string $blockId): bool
    {
        $block = TgsModels::block()::find($blockId);
        if ($block === null) {
            return false;
        }

        $host = $this->host();

        return ($block->owner_container_type === $host->containerType
                && $block->owner_container_id === $host->containerId)
            || $block->owner_container_type === 'orphan';
    }

    /**
     * Hotfix-срез 1, баг 1: единая точка, где пикер (insertFromLibrary/insertFromToken/
     * insertFromCollection) вычисляет цель вставки из `pickerParentId`/`pickerSlot` и проверяет её
     * — ровно те же условия, что у addChildBlock(): родитель принадлежит смонтированному хосту и
     * не залочен для текущего пользователя. `pickerParentId` — публичное Livewire-свойство, а
     * дровер мог быть открыт ДО того, как родителя залочили, поэтому гейт openPicker() не
     * достаточен. null — вставлять некуда (при локе редактор уже получил уведомление, см.
     * refuseLockedParent()); корень хоста (`pickerParentId === null`) не гейтится локом — лок
     * бывает только у блока.
     *
     * @return array{0: string, 1: string, 2: string}|null [containerType, containerId, slot]
     */
    private function resolvePickerTarget(): ?array
    {
        $host = $this->host();

        if ($this->pickerParentId === null) {
            return [$host->containerType, $host->containerId, $host->slot];
        }

        if (! $this->blockBelongsToHost($this->pickerParentId) || $this->refuseLockedParent($this->pickerParentId)) {
            return null;
        }

        return ['block', $this->pickerParentId, (string) $this->pickerSlot];
    }

    /**
     * Task 3 (2a-1 "механика ссылок"): true, если `$blockId` существует, но не принадлежит
     * смонтированному хосту — т.е. это ссылка (insertReference), а не собственный/orphan-блок.
     * Гейтит read-only UI в _node.blade.php (скрытые edit/lock/add-в-слот кнопки).
     */
    public function isForeignBlock(string $blockId): bool
    {
        return TgsModels::block()::find($blockId) !== null && ! $this->blockBelongsToHost($blockId);
    }

    /**
     * True when `$placementId` is NOT the first-created `BlockPlacement` row for `$blockId`
     * — i.e. this specific node is a reference to an earlier placement of the same block,
     * not its origin. Deliberately NOT ownership-based (unlike `isForeignBlock()`):
     * `insertFromToken()`'s "as reference" option lets a host paste a block's own id back
     * into a slot on the SAME page it already owns, which `isForeignBlock()` can never flag
     * (owner matches this host both times) — this is placement-identity-based instead, so
     * it catches that case too, and correctly leaves the origin placement unbadged (a
     * block_id can never be referenced before its first placement exists, so "is there an
     * earlier placement row" unambiguously means "is this the origin").
     */
    public function isReferencePlacement(int $placementId, string $blockId): bool
    {
        return TgsModels::placement()::query()->where('block_id', $blockId)->where('id', '<', $placementId)->exists();
    }

    /**
     * Task 1 (плейбук "линки как ссылки"): source-осведомлённый лейбл для foreign-блоков —
     * "чужой" (owner != host) технический, протекает в UI. Владелец = library-хост →
     * «Из библиотеки»; прочее (другая страница/коллекция) → нейтральное «Связанный».
     * Расширяемо: будущие источники (токен/коллекции) добавят свои ветки сюда же.
     */
    public function referenceSourceLabel(string $blockId): string
    {
        $block = TgsModels::block()::find($blockId);
        $libraryHost = $this->libraryHost();

        if ($block !== null
            && $block->owner_container_type === $libraryHost->containerType
            && $block->owner_container_id === $libraryHost->containerId) {
            return __('tgs::filament.tgs.reference.from_library');
        }

        return __('tgs::filament.tgs.reference.linked');
    }

    /**
     * Library page only (_node.blade.php gates the call to $isLibraryHost && depth === 0):
     * how many times this library block is inserted as a shared reference elsewhere — every
     * BlockPlacement row for $blockId whose container is NOT the library host's own root
     * (that one placement is the block's "home" in the library itself, not a usage of it).
     * Only meaningful for a library block's own root placement — a block nested inside a
     * composite library block isn't independently referenceable via insertFromLibrary(),
     * so this is never called for depth > 0 nodes.
     */
    public function libraryUsageCount(string $blockId): int
    {
        $libraryHost = $this->libraryHost();

        return TgsModels::placement()::query()
            ->where('block_id', $blockId)
            ->where(function ($query) use ($libraryHost): void {
                $query->where('container_type', '!=', $libraryHost->containerType)
                    ->orWhere('container_id', '!=', $libraryHost->containerId);
            })
            ->count();
    }

    /**
     * Этап 3, Слайс B ("где используется"): general-case sibling of libraryUsageCount() — how
     * many distinct root hosts $blockId resolves up to (CanvasPersistence::resolveUsagePlaces(),
     * DAG-safe), not just non-library placements of a library block. Used both by the node
     * badge (_node.blade.php) and editBlockAction()'s modal description, both of which only need
     * the count and not the (pricier, label/url-resolving) full list — kept as a separate cheap
     * call rather than `count($this->usagePlaces($blockId))` so a tree render doesn't pay for
     * label/resource resolution on every single node.
     */
    public function usagePlacesCount(string $blockId): int
    {
        return count(app(CanvasPersistence::class)->resolveUsagePlaces($blockId));
    }

    /**
     * Node header priority/compaction plan follow-up: total LIVE `BlockPlacement` rows for
     * $blockId, regardless of host. Deliberately NOT the same thing as usagePlacesCount()
     * (which dedupes by root host via CanvasPersistence::resolveUsagePlaces()) —
     * insertFromToken()'s "as reference" option lets a host paste a block's own id back into
     * a slot on the SAME page it already owns, and that second placement resolves to the
     * exact same root host as the origin, so usagePlacesCount() stays 1 and the origin never
     * clears its `> 1` chip gate even though a second, visibly-badged placement of the same
     * block sits right there. This is the metric the node badge (_node.blade.php) actually
     * gates and displays on; usagePlacesCount()/usagePlaces() keep their own host-based
     * meaning for the "where is this used" dropdown and editBlockAction()'s modal note, where
     * listing the SAME host twice wouldn't add anything.
     */
    public function blockPlacementsCount(string $blockId): int
    {
        return TgsModels::placement()::query()->where('block_id', $blockId)->count();
    }

    /**
     * Этап 4, Слайс E (Требование 4.1): число живых вложенных блоков под $blockId, для текста
     * подтверждения удаления контейнера ("Удалить «Аккордеон» и 23 вложенных блока?"). Тонкая
     * обёртка над `CanvasPersistence::countLiveDescendants()` — лист/пустой контейнер естественно
     * дают 0, без отдельной ветки "is this a container" здесь.
     */
    public function nestedLiveDescendantsCount(string $blockId): int
    {
        return app(CanvasPersistence::class)->countLiveDescendants($blockId);
    }

    /**
     * Отображаемая метка блока по id (Этап 4, Слайс E) — тип резолвится напрямую из БД, т.к.
     * deleteBlockAction()'s arguments несут только id/parent/slot, не type (в отличие от
     * editBlockAction). Собственное имя блока (`\ApiMedia\Tgs\Support\TgsModels::block()::$name`), если задано, предпочтительнее
     * generic-метки типа — тот же приоритет, что и `TgsTrashDrawer::describeEntry()`'s preview.
     */
    private function blockDisplayLabel(string $blockId): string
    {
        $block = TgsModels::block()::find($blockId);
        if ($block === null) {
            return $blockId;
        }

        return $block->name ?? $this->blockMeta($block->type)['label'];
    }

    /**
     * Этап 3, Слайс B: full "где используется" list for $blockId — every root host
     * CanvasPersistence::resolveUsagePlaces() finds, each resolved to a human label and (when
     * available) an admin edit url via HostLabelResolver. Backs the node's usage-count
     * dropdown (_node.blade.php) — the list is handed to Blade/Alpine in full up front (Alpine
     * `x-show` toggle), no per-click round trip.
     *
     * @return list<array{containerType: string, containerId: string, slot: string, label: string, url: string|null}>
     */
    public function usagePlaces(string $blockId): array
    {
        $resolver = app(HostLabelResolver::class);

        return array_map(
            static function (HostDescriptor $host) use ($resolver): array {
                $described = $resolver->describe($host);

                return [
                    'containerType' => $host->containerType,
                    'containerId' => $host->containerId,
                    'slot' => $host->slot,
                    'label' => $described['label'],
                    'url' => $described['url'],
                ];
            },
            app(CanvasPersistence::class)->resolveUsagePlaces($blockId),
        );
    }

    /**
     * Task 3: контейнер-цель хоста для root-узлов (host() приватен и не виден blade) — нужен
     * _node.blade.php для формирования аргументов detachBlockAction() на корневом блоке
     * (для дочернего блока контейнер тривиален: `['block', $parentBlockId, $parentSlot]`).
     *
     * @return array{containerType: string, containerId: string, slot: string}
     */
    public function rootContainer(): array
    {
        $host = $this->host();

        return ['containerType' => $host->containerType, 'containerId' => $host->containerId, 'slot' => $host->slot];
    }

    /**
     * Дублирует корневой блок: глубокая копия поддерева (+ медиа) через
     * CanvasPersistence::duplicateBlock, вставляется сразу после исходника.
     */
    public function duplicateRootBlock(string $blockId): void
    {
        $this->guardWritable();

        if (! $this->blockBelongsToHost($blockId) || TgsBlock::isLockedForCurrentUser($blockId)) {
            return;
        }

        $host = $this->host();
        app(CanvasPersistence::class)->duplicateBlock(
            $host, $host->containerType, $host->containerId, $host->slot, $blockId,
        );
        $this->markSaved();
    }

    public function duplicateChildBlock(string $parentBlockId, string $slot, string $blockId): void
    {
        $this->guardWritable();

        if (! $this->blockBelongsToHost($blockId) || ! $this->blockBelongsToHost($parentBlockId)
            || TgsBlock::isLockedForCurrentUser($blockId)) {
            return;
        }

        app(CanvasPersistence::class)->duplicateBlock($this->host(), 'block', $parentBlockId, $slot, $blockId);
        $this->markSaved();
    }

    /**
     * Список типов блоков для галереи вставки (Task 8, plan B2): корень (без родителя/слота) и
     * вставка в неограниченный слот контейнера (BlockChildPolicy::allowedChildrenFor() вернул [] —
     * без явного allowedChildren) — все зарегистрированные блоки, кроме child-only
     * (isChildOnlyBlock()); вставка в слот с явным allowedChildren — только перечисленные там типы
     * (позитивный allowlist сам включает child-only блок, если он в списке).
     *
     * @return list<array{type: string, label: string, icon: string|\BackedEnum|Htmlable|null, category: string|null}>
     */
    public function availableBlocksFor(?string $parentType, ?string $slot, ?string $parentId = null): array
    {
        $classes = array_values(array_filter(
            ContentBuilderFactory::blockClasses(),
            fn (string $class): bool => app(TgsInsertionPolicy::class)->allows($class::blockKey(), $parentType, $slot, $this->host(), $parentId),
        ));
        $blocks = array_map(static fn (string $class): array => self::describeBlock($class), $classes);
        usort($blocks, static fn (array $a, array $b): int => $a['category_order'] <=> $b['category_order']);

        return $blocks;
    }

    /**
     * Открывает единый правый дровер выбора блока (plan 2b): parent=null — вставка в корень,
     * иначе — в указанный слот $parentBlockId. Игнорирует чужой $parentBlockId (та же
     * ownership-проверка, что и addChildBlock/duplicateChildBlock).
     */
    public function openPicker(?string $parentBlockId = null, ?string $slot = null, ?int $position = null): void
    {
        if ($parentBlockId !== null && ! $this->blockBelongsToHost($parentBlockId)) {
            return;
        }

        $this->pickerParentId = $parentBlockId;
        $this->pickerSlot = $slot;
        $this->pickerPosition = $position;
        // Каждое новое открытие дровера должно стартовать с галереи типов — иначе выбор
        // вкладки «Из библиотеки» на одном месте вставки залипал бы для следующей цели
        // (другой слот/родитель), что было бы неожиданно. Сброс перенесён сюда (было в
        // closePicker()) — так поведение не зависит от того, КАК дровер был закрыт до этого
        // (нативная кнопка Filament «Отмена» не знает про pickerTab и не обязана его сбрасывать).
        $this->pickerTab = 'new';
        $this->mountAction('insertBlockAction');
    }

    public function closePicker(): void
    {
        $this->unmountAction();
    }

    /**
     * Bug fix (2026-07-26): popping 2+ nested action-modals via multiple unmountAction() calls
     * in one request (insertFromToken()/insertFromCollection(), both close picker + own nested
     * action) leaves the page scroll permanently locked. Root cause, confirmed by tracing the
     * actual browser events (repro via headless Chromium): each unmountAction() call queues its
     * own `sync-action-modals` Livewire dispatch, and the client's syncActionModals() handler
     * (vendor/filament/actions/resources/js/components/modals.js) always closes a modal
     * "quietly" (`close-modal-quietly`) when stepping between nesting levels — quiet close only
     * flips `isOpen`, never `isTrapActive` (see vendor .../support/resources/views/components/
     * modal/index.blade.php's x-on bindings). `isTrapActive` is what Alpine's
     * `x-trap.noscroll="isTrapActive"` watches to release the scroll lock it applied via
     * disableScrolling() (vendor/livewire/livewire/dist/livewire.esm.js). When 2 modals close
     * in the SAME request with no real "step back down to a still-open parent" in between,
     * neither modal ever receives a real (non-quiet) close, so neither trap ever deactivates.
     * A single unmountAction() (the common case) doesn't hit this — confirmed via a control
     * test — the one-modal-at-a-time close is fully covered by Filament/Alpine's own machinery.
     * forceRender() (the fix used for the sibling "stale canvas behind modal" bug in
     * openDraftEditor()) does NOT fix this specific symptom — traced and confirmed empirically.
     * The only reliable fix at the application level is to unconditionally reset the CSS state
     * `x-trap.noscroll` left behind, via a client-side `$this->js()` dispatch that always runs
     * after the response, regardless of the modal DOM/event bookkeeping above.
     */
    private function releaseStuckScrollLock(): void
    {
        // setTimeout defers past the SAME response's own event-dispatch processing — one of the
        // sync-action-modals-driven `open-modal` events re-triggers Alpine's disableScrolling()
        // AFTER a synchronous reset would run, clobbering it back to `overflow: hidden` (traced
        // empirically). Running on the next tick guarantees this fires last.
        $this->js('setTimeout(() => { document.documentElement.style.overflow = ""; document.documentElement.style.removeProperty("padding-right"); }, 0);');
    }

    /**
     * §2b UX follow-up (2026-07-22, drawer→slideOver conversion): closePicker() runs FIRST,
     * before addRootBlock()/addChildBlock() — unmounting insertBlockAction so that
     * editBlockAction (auto-opened by openDraftEditor() inside those calls, for leaf types)
     * mounts as a plain TOP-LEVEL action, exactly as before this conversion. The alternative
     * (leave the picker mounted, let editBlockAction mount NESTED under it) doesn't fit
     * Filament's nested-action stack model at all: closing an ancestor action closes its
     * descendants too, but here we want the OPPOSITE — the picker closes while the edit modal
     * takes over independently. Container types (blockDeclaresSlots()) don't open any modal
     * here either way, so this reordering is a no-op for them beyond closing the picker sooner.
     */
    public function chooseBlock(string $type): void
    {
        $this->guardWritable();
        $this->closePicker();

        if ($this->pickerParentId === null) {
            $this->addRootBlock($type, $this->pickerPosition);
        } else {
            $this->addChildBlock($this->pickerParentId, (string) $this->pickerSlot, $type, $this->pickerPosition);
        }
    }

    /**
     * Список блоков для дровера с учётом текущей цели (plan 2b): корень (parent null) —
     * все типы; слот контейнера — отфильтровано через availableBlocksFor() по allowedChildren.
     *
     * @return list<array{type: string, label: string, icon: string|\BackedEnum|Htmlable|null, category: string|null}>
     */
    #[Computed]
    public function getPickerBlocksProperty(): array
    {
        $parentType = $this->pickerParentId !== null
            ? TgsModels::block()::find($this->pickerParentId)?->type
            : null;

        return $this->availableBlocksFor($parentType, $this->pickerSlot, $this->pickerParentId);
    }

    /**
     * true, если цель пикера — слот контейнера с урезанным allowedChildren
     * (список короче полного набора типов). Для корня всегда false.
     */
    #[Computed]
    public function getPickerIsFilteredProperty(): bool
    {
        if ($this->pickerParentId === null) {
            return false;
        }

        return count($this->getPickerBlocksProperty()) < count(ContentBuilderFactory::blockClasses());
    }

    /**
     * Список блоков библиотеки (срез 2a-2): плоский корневой список library-хоста
     * (каждый корневой узел — отдельный переиспользуемый блок), с label/preview
     * для карточек дровера. Отфильтрован по той же allowedChildren-политике, что и
     * вкладка "Новый" (finding #1, final review): библиотека — ещё один источник
     * блоков для текущей цели пикера, а не байпас слот-ограничений.
     *
     * @return list<array{id: string, type: string, label: string, preview: ?string, name: ?string}>
     */
    #[Computed]
    public function getLibraryBlocksProperty(): array
    {
        $nodes = app(CanvasTreeReader::class)->read($this->libraryHost());

        $allowedTypes = array_column($this->getPickerBlocksProperty(), 'type');

        $nodes = array_values(array_filter(
            $nodes,
            static fn (array $node): bool => in_array($node['type'], $allowedTypes, true),
        ));

        return array_map(fn (array $node): array => [
            'id' => $node['id'],
            'type' => $node['type'],
            'label' => $this->blockMeta($node['type'])['label'],
            'preview' => $node['previewLine'],
            'name' => $node['name'],
        ], $nodes);
    }

    /**
     * Вставляет библиотечный блок в текущую цель пикера (срез 2a-2): $asCopy=true —
     * независимая копия (copyBlockInto), false — общая ссылка (insertReference, тот же
     * block_id, владение не меняется). Цель — pickerParentId/pickerSlot/pickerPosition,
     * выставленные openPicker() (parent null = корень хоста).
     *
     * Defense in depth (finding #1, final review): getLibraryBlocksProperty() already hides
     * types not allowed for the current picker target, but this mutator re-checks the
     * invariant itself — same pattern as duplicateRootBlock()/deleteChildBlock()/
     * reorderChild() not trusting blade-level hiding alone. No-op on a disallowed type.
     *
     * Ownership guard (finding, second review round): pickerParentId is a public Livewire
     * property, regidratable directly from the client, bypassing openPicker()'s ownership
     * gate — must be re-checked here too, same as addChildBlock()/duplicateChildBlock().
     * null is the legitimate "insert into host root" case and is not guarded.
     */
    public function insertFromLibrary(string $libraryBlockId, bool $asCopy): void
    {
        $this->guardWritable();

        $target = $this->resolvePickerTarget();
        if ($target === null) {
            $this->closePicker();

            return;
        }

        $libraryBlockType = TgsModels::block()::find($libraryBlockId)?->type;
        $allowedTypes = array_column($this->getPickerBlocksProperty(), 'type');

        if ($libraryBlockType === null || ! in_array($libraryBlockType, $allowedTypes, true)) {
            return;
        }

        $host = $this->host();
        [$containerType, $containerId, $slot] = $target;

        $persistence = app(CanvasPersistence::class);

        if ($asCopy) {
            $persistence->copyBlockInto($host, $containerType, $containerId, $slot, $libraryBlockId, $this->pickerPosition);
        } else {
            $persistence->insertReference($host, $containerType, $containerId, $slot, $libraryBlockId, $this->pickerPosition);
        }

        $this->markSaved();
        $this->closePicker();
    }

    /**
     * Token-срез governing-дизайна (docs/superpowers/specs/2026-07-20-block-reuse-mechanisms-design.md):
     * то же самое копия/ссылка, что и insertFromLibrary(), только источник — сырой block_id
     * («токен»), введённый пользователем вручную, а не курируемый список. Валидация
     * (существование, допустимость типа, цикл при вставке-ссылкой) уже отработала на уровне
     * insertFromTokenAction()'s schema rule() — этот метод не доверяет только форме и
     * повторяет ту же проверку здесь, тем же паттерном, что и insertFromLibrary().
     *
     * Ownership guard: pickerParentId — публичное Livewire-свойство, регидратируемое прямо с
     * клиента, минуя ownership-гейт openPicker() — перепроверяется здесь же, как и в
     * insertFromLibrary()/addChildBlock()/duplicateChildBlock(). null — легитимный «в корень
     * хоста», не гейтится.
     */
    public function insertFromToken(string $token, bool $asCopy): void
    {
        $this->guardWritable();

        $target = $this->resolvePickerTarget();
        if ($target === null) {
            $this->closePicker();

            return;
        }

        $tokenBlockType = TgsModels::block()::find($token)?->type;
        $allowedTypes = array_column($this->getPickerBlocksProperty(), 'type');

        if ($tokenBlockType === null || ! in_array($tokenBlockType, $allowedTypes, true)) {
            return;
        }

        $host = $this->host();
        [$containerType, $containerId, $slot] = $target;

        $persistence = app(CanvasPersistence::class);

        if ($asCopy) {
            $persistence->copyBlockInto($host, $containerType, $containerId, $slot, $token, $this->pickerPosition);
        } else {
            $persistence->insertReference($host, $containerType, $containerId, $slot, $token, $this->pickerPosition);
        }

        $this->markSaved();
        $this->closePicker();
    }

    /**
     * Task 3 (block collections): список коллекций для вкладки "Из коллекции" (Task 4) —
     * провизорные коллекции исключены автоматически глобальным скоупом
     * HasProvisionalRecordTrait, здесь ничего дополнительно фильтровать не нужно.
     *
     * @return list<array{id: int, name: ?string, description: ?string}>
     */
    #[Computed]
    public function getCollectionsProperty(): array
    {
        return TgsModels::collection()::query()->get()
            ->map(static fn (Collection $collection): array => [
                'id' => $collection->id,
                'name' => $collection->name,
                'description' => $collection->description,
            ])
            ->all();
    }

    /**
     * Task 3: верхнеуровневые блоки группы 'blocks' коллекции $collectionId — источник для
     * вкладки "Из коллекции" (Task 4). В отличие от getLibraryBlocksProperty(), НЕ
     * фильтруется по allowedChildren текущей цели пикера: план требует показать ВСЕ
     * верхнеуровневые блоки коллекции в модалке выбора, отбор допустимых для текущей цели
     * (если понадобится) — забота UI-слоя Task 4, не этого метода.
     *
     * @return list<array{id: string, type: string, label: string, name: ?string, preview: ?string}>
     */
    public function collectionBlocks(int $collectionId): array
    {
        $collection = TgsModels::collection()::find($collectionId);
        if ($collection === null) {
            return [];
        }

        $nodes = app(CanvasTreeReader::class)->read($collection->canvas('blocks')->hostDescriptor());

        return array_map(fn (array $node): array => [
            'id' => $node['id'],
            'type' => $node['type'],
            'label' => $this->blockMeta($node['type'])['label'],
            'name' => $node['name'],
            'preview' => $node['previewLine'],
        ], $nodes);
    }

    /**
     * Task 3: вставляет выбранные блоки коллекции $collectionId в текущую цель пикера как
     * независимые копии (copyBlockInto) — никогда ссылками, план явно требует отсутствие
     * синхрона с коллекцией. Порядок вставки следует порядку $blockIds (порядку выбора
     * пользователя), а не порядку блоков внутри коллекции.
     *
     * Validation (план: "не доверять клиенту"): $blockIds отфильтрованы до подмножества
     * реальных верхнеуровневых id коллекции — чужой/несуществующий id молча отбрасывается,
     * не роняет запрос. Несуществующая коллекция — no-op.
     *
     * Ownership guard (тот же паттерн, что insertFromLibrary()): pickerParentId — публичное
     * Livewire-свойство, регидратируемое клиентом напрямую, минуя openPicker()'s гейт.
     *
     * Positioning: copyBlockInto() с фиксированным $position сдвигает существующие
     * placement'ы вправо и вставляет ИМЕННО на эту позицию — несколько вызовов подряд с
     * одним и тем же $position окажутся в обратном порядке. Чтобы сохранить порядок выбора
     * пользователя при вставке в середину (pickerPosition !== null), позиция инкрементируется
     * на каждой итерации; при вставке в конец (pickerPosition === null) передаём null на
     * каждой итерации — copyBlockInto's null уже означает "добавить в конец", что само по
     * себе сохраняет порядок при последовательных вызовах.
     *
     * @param  list<string>  $blockIds
     */
    public function insertFromCollection(int $collectionId, array $blockIds): void
    {
        $this->guardWritable();

        $target = $this->resolvePickerTarget();
        if ($target === null) {
            // Same double pop as the success path below (this call comes from the nested
            // insertFromCollectionAction).
            $this->unmountAction();
            $this->unmountAction();
            $this->releaseStuckScrollLock();

            return;
        }

        $collection = TgsModels::collection()::find($collectionId);
        if ($collection === null) {
            return;
        }

        $allowedIds = array_column($this->collectionBlocks($collectionId), 'id');
        $blockIds = array_values(array_intersect($blockIds, $allowedIds));

        if ($blockIds === []) {
            return;
        }

        $host = $this->host();
        [$containerType, $containerId, $slot] = $target;

        $persistence = app(CanvasPersistence::class);

        foreach ($blockIds as $blockId) {
            app(TgsInsertionPolicy::class)->ensureSubtree($blockId, $containerType, $containerId, $slot, $host);
        }

        foreach ($blockIds as $index => $blockId) {
            $position = $this->pickerPosition !== null ? $this->pickerPosition + $index : null;
            $persistence->copyBlockInto($host, $containerType, $containerId, $slot, $blockId, $position);
        }

        $this->markSaved();

        // Called from insertFromCollectionAction()'s own submit — that action is mounted
        // NESTED under insertBlockAction (registered via registerModalActions() so it resolves
        // at all), so a single unmountAction() would only pop it, leaving the picker slide-over
        // open behind it. Pop twice to close both, matching pre-conversion behavior where a
        // successful collection insert closed the whole drawer.
        $this->unmountAction();
        $this->unmountAction();
        $this->releaseStuckScrollLock();
    }

    /**
     * Добавляет блок хоста в библиотеку (срез 2a-2): независимая глубокая копия
     * (copyBlockInto) в library-хост — оригинал на канвасе не трогается, не превращается
     * в ссылку.
     *
     * Ownership guard (finding #2, final review): blade already hides this button on
     * foreign/locked nodes, but the method itself must re-check — mirrors
     * duplicateRootBlock()'s guard verbatim, defense in depth like every other mutator
     * in this file that takes a client-supplied block id.
     */
    /**
     * Task 6: turns "add to library" from a one-click copy into a small modal asking for an
     * optional name — mirrors addToLibrary()'s old guard/copy flow exactly, then names the
     * root clone when a name was given (empty/omitted → left unnamed).
     *
     * Task 2 (tgs-canvas as entities, extract to library): added an opt-in
     * "replace_with_reference" checkbox — when checked, the flow becomes an atomic extract
     * (CanvasPersistence::extractToLibrary): copy into the library, delete the original from
     * its current position, insert a reference to the library copy at that same position.
     * Left unchecked, behavior is unchanged (independent copy, original untouched).
     * Arguments now carry {block, parent, slot} — same shape as deleteBlockAction/
     * detachBlockAction — so extract knows where to delete-from/insert-back-into.
     */
    public function addToLibraryAction(): Action
    {
        return Action::make('addToLibraryAction')
            ->visible(fn (): bool => Gate::allows('tgs.manageLibrary'))
            ->label(__('tgs::filament.tgs.actions.add_to_library.label'))
            ->schema([
                TextInput::make('name')
                    ->label(__('tgs::filament.tgs.canvas.library.name_field.label'))
                    ->helperText(__('tgs::filament.tgs.canvas.library.name_field.helper'))
                    ->maxLength(255),
                Checkbox::make('replace_with_reference')
                    ->label(__('tgs::filament.tgs.actions.add_to_library.replace'))
                    ->helperText(__('tgs::filament.tgs.actions.add_to_library.replace_helper'))
                    ->default(false),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->guardWritable();
                Gate::authorize('tgs.manageLibrary');

                $blockId = $arguments['block'];

                if (! $this->blockBelongsToHost($blockId) || TgsBlock::isLockedForCurrentUser($blockId)) {
                    return;
                }

                $name = ($data['name'] ?? null) ?: null;

                if ($data['replace_with_reference'] ?? false) {
                    $host = $this->host();
                    $parent = $arguments['parent'] ?? null;
                    $containerType = $parent === null ? $host->containerType : 'block';
                    $containerId = $parent ?? $host->containerId;
                    $slot = $parent === null ? $host->slot : $arguments['slot'];

                    $clone = app(CanvasPersistence::class)->extractToLibrary(
                        $this->libraryHost(), $host, $containerType, $containerId, $slot, $blockId, $name,
                    );

                    if ($clone !== null) {
                        $this->markSaved();
                    }
                } else {
                    $host = $this->libraryHost();

                    $clone = app(CanvasPersistence::class)->copyBlockInto(
                        $host, $host->containerType, $host->containerId, $host->slot, $blockId,
                    );

                    if ($clone !== null && $name !== null) {
                        app(CanvasPersistence::class)->renameBlock($host, $clone->id, $name);
                    }
                }

                Notification::make()
                    ->title(__('tgs::filament.tgs.canvas.library.added'))
                    ->success()
                    ->send();
            });
    }

    /**
     * §2b UX follow-up (2026-07-22): the insert drawer itself, native Filament `->slideOver()` —
     * replaces the hand-rolled Alpine backdrop+panel (see TgsBlockHistoryDrawer's own conversion
     * earlier the same day for the pattern this mirrors). `registerModalActions()` is REQUIRED
     * for insertFromCollectionAction: it's mounted via `mountAction('insertFromCollectionAction', ...)`
     * from a button INSIDE this action's own modalContent (the Collections tab), i.e. WHILE this
     * action is already mounted — Filament resolves that as a NESTED action via
     * `$parentAction->getModalAction($name)`, not the usual "is there a component method named
     * this" lookup (confirmed empirically for viewDiffAction/historyAction the same day: without
     * registering it here, mounting attempts get silently unmounted). editBlockAction is
     * DELIBERATELY NOT registered/nested here — see chooseBlock()'s docblock for why closing the
     * picker BEFORE opening it keeps editBlockAction a plain top-level action instead.
     */
    public function insertBlockAction(): Action
    {
        return Action::make('insertBlockAction')
            ->modalHeading(__('tgs::filament.tgs.canvas.add_block'))
            ->slideOver()
            ->modalWidth(Width::Large)
            ->modalSubmitAction(false)
            ->registerModalActions([$this->insertFromCollectionAction(), $this->insertFromTokenAction()])
            ->modalContent(fn (): View => view('tgs::_insert-picker', [
                'pickerTab' => $this->pickerTab,
                'pickerBlocks' => $this->getPickerBlocksProperty(),
                'pickerIsFiltered' => $this->getPickerIsFilteredProperty(),
                'libraryBlocks' => $this->getLibraryBlocksProperty(),
                'collections' => $this->getCollectionsProperty(),
                // On the library canvas itself, inserting FROM the library (or from a
                // collection) into itself is circular/confusing — same reasoning as
                // addToLibraryAction's own kebab-menu hiding on $isLibraryHost.
                'isLibraryHost' => $this->isLibraryHost(),
            ]));
    }

    /**
     * Task 4 (block collections): модалка выбора верхнеуровневых блоков коллекции
     * $arguments['collection'], открытая кликом по карточке коллекции во вкладке
     * "Коллекции" (blade). Дефолт — все блоки выбраны (план: "дефолт все"), сабмит просто
     * делегирует в уже готовый insertFromCollection() (Task 3, сам делает
     * markSaved()+закрытие двух уровней — см. insertFromCollection()).
     *
     * modalHeading falls back to the generic tab label when the collection has vanished
     * between the drawer render and the click (race/stale tab) — must not 500.
     */
    public function insertFromCollectionAction(): Action
    {
        return Action::make('insertFromCollectionAction')
            ->modalHeading(function (array $arguments): string {
                $collection = TgsModels::collection()::find((int) $arguments['collection']);
                $fallback = __('tgs::filament.tgs.canvas.collections.tab_collections');

                if ($collection === null) {
                    return $fallback;
                }

                return $collection->name ?? $fallback;
            })
            ->schema(function (array $arguments): array {
                $items = $this->collectionBlocks($arguments['collection']);

                $options = collect($items)->mapWithKeys(static fn (array $item): array => [
                    $item['id'] => $item['name'] !== null && $item['name'] !== ''
                        ? "{$item['label']} · {$item['name']}"
                        : $item['label'],
                ])->all();

                return [
                    CheckboxList::make('blocks')
                        ->label(__('tgs::filament.tgs.canvas.collections.blocks_field.label'))
                        ->options($options)
                        ->default(array_column($items, 'id'))
                        ->bulkToggleable(),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $this->guardWritable();

                $this->insertFromCollection($arguments['collection'], $data['blocks'] ?? []);
            });
    }

    /**
     * §Token-срез: вкладка "По токену" в дровере "Добавить блок" — вложенный Action по
     * образцу insertFromCollectionAction() (registerModalActions() на insertBlockAction()
     * REQUIRED для резолва, та же причина — см. insertBlockAction()'s docblock). Схема даёт
     * инлайн-валидацию поля «бесплатно» через Filament: существование блока, допустимость
     * типа для текущей цели пикера, и цикл (только для ссылки — копия всегда получает новый
     * id, см. CanvasPersistence::wouldCreateCycle()'s docblock).
     */
    public function insertFromTokenAction(): Action
    {
        return Action::make('insertFromTokenAction')
            ->modalHeading(__('tgs::filament.tgs.canvas.token.heading'))
            ->schema([
                TextInput::make('token')
                    ->label(__('tgs::filament.tgs.canvas.token.field_label'))
                    ->required()
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $block = TgsModels::block()::find($value);

                        if ($block === null) {
                            $fail(__('tgs::filament.tgs.canvas.token.errors.not_found'));

                            return;
                        }

                        $allowedTypes = array_column($this->getPickerBlocksProperty(), 'type');

                        if (! in_array($block->type, $allowedTypes, true)) {
                            $fail(__('tgs::filament.tgs.canvas.token.errors.type_not_allowed'));

                            return;
                        }

                        if ((bool) $get('asCopy')) {
                            return;
                        }

                        $host = $this->host();
                        $containerType = $this->pickerParentId === null ? $host->containerType : 'block';
                        $containerId = $this->pickerParentId === null ? $host->containerId : $this->pickerParentId;

                        if (app(CanvasPersistence::class)->wouldCreateCycle($value, $containerType, $containerId)) {
                            $fail(__('tgs::filament.tgs.canvas.token.errors.cycle'));
                        }
                    }),
                Radio::make('asCopy')
                    ->label(__('tgs::filament.tgs.canvas.library.insert_as_label'))
                    ->boolean(__('tgs::filament.tgs.canvas.library.insert_as_copy'), __('tgs::filament.tgs.canvas.library.insert_as_reference'))
                    ->descriptions([
                        1 => __('tgs::filament.tgs.canvas.library.insert_as_copy_description'),
                        0 => __('tgs::filament.tgs.canvas.library.insert_as_reference_description'),
                    ])
                    ->default(true)
                    ->inline(),
            ])
            ->modalSubmitActionLabel(__('tgs::filament.tgs.canvas.token.insert_button'))
            ->action(function (array $data): void {
                $this->guardWritable();

                $this->insertFromToken($data['token'], (bool) $data['asCopy']);

                // insertFromToken() already called closePicker() (a single unmountAction()),
                // popping this NESTED insertFromTokenAction off — but insertBlockAction (the
                // picker drawer itself) is still open behind it. Pop it too, matching
                // insertFromCollection()'s own double-unmount for the exact same nesting
                // reason (see that method's docblock).
                $this->unmountAction();
                $this->releaseStuckScrollLock();
            });
    }

    /**
     * Публичный (Этап 4, Слайс D): та же процедура типовых метаданных (иконка/подпись),
     * которую blockMeta() оборачивает для узлов дерева — TgsTrashDrawer, отдельный
     * Livewire-компонент без доступа к private-методам этого класса, переиспользует её
     * напрямую для строк корзины вместо повторной реализации резолва иконки/подписи.
     *
     * @return array{type: string, label: string, icon: string|\BackedEnum|Htmlable|null, category: string|null, category_label: string|null, category_icon: string|null, category_order: int, layout: string}
     */
    public static function describeBlock(string $class): array
    {
        $block = $class::make();
        $label = $block->getBlockLabel();
        $label = match (true) {
            $label instanceof Closure => $label(),
            $label instanceof Htmlable => $label->toHtml(),
            default => (string) $label,
        };

        $declaredCategory = $block->blockCategory();
        $category = $declaredCategory !== null
            ? app(TgsBlockRegistry::class)->category($declaredCategory->key) ?? $declaredCategory
            : null;

        return [
            'type' => $class::blockKey(),
            'label' => $label,
            'icon' => $block->getIcon(),
            'category' => $category?->key,
            'category_label' => $category?->label,
            'category_icon' => $category?->icon,
            'category_order' => $category !== null ? $category->order : 0,
            'layout' => $block->blockLayout()->value,
        ];
    }

    /**
     * §12 (plan 2b): блокирует блок (cascade — surface-режим не выставляется, как в старом
     * Builder). Требует tgs.manageLocks. Гейт владения — блок
     * должен принадлежать смонтированному хосту.
     */
    public function lockBlock(string $blockId): void
    {
        $this->guardWritable();

        if (! TgsBlock::currentUserCanManageLocks() || ! $this->blockBelongsToHost($blockId)) {
            return;
        }

        app(CanvasPersistence::class)->setLock($this->host(), $blockId, true);
        $this->markSaved();
    }

    public function unlockBlock(string $blockId): void
    {
        $this->guardWritable();

        if (! TgsBlock::currentUserCanManageLocks() || ! $this->blockBelongsToHost($blockId)) {
            return;
        }

        app(CanvasPersistence::class)->setLock($this->host(), $blockId, false);
        $this->markSaved();
    }

    public function deleteRootBlock(string $blockId): void
    {
        $this->guardWritable();

        if (TgsBlock::isLockedForCurrentUser($blockId)) {
            return;
        }

        $host = $this->host();
        app(CanvasPersistence::class)->deleteBlock(
            $host, $host->containerType, $host->containerId, $host->slot, $blockId,
        );
        $this->markSaved();
    }

    public function deleteChildBlock(string $parentBlockId, string $slot, string $blockId): void
    {
        $this->guardWritable();

        // Ownership guard (finding #1, final review, defense in depth alongside the blade gate):
        // $parentBlockId is a nested foreign block when the node lives inside a foreign
        // subtree — deleting there would unlink the placement inside the SHARED container
        // itself, visible to every host referencing it. Mirrors reorderChild()'s guard below.
        if (! $this->blockBelongsToHost($parentBlockId) || TgsBlock::isLockedForCurrentUser($blockId)) {
            return;
        }

        app(CanvasPersistence::class)->deleteBlock(
            $this->host(), 'block', $parentBlockId, $slot, $blockId,
        );
        $this->markSaved();
    }

    /**
     * @param  list<string>  $orderedIds
     */
    public function reorderRoot(array $orderedIds): void
    {
        $this->guardWritable();

        foreach ($orderedIds as $id) {
            if (TgsBlock::isLockedForCurrentUser($id)) {
                return;
            }
        }

        $host = $this->host();
        app(CanvasPersistence::class)->reorderSlot(
            $host->containerType, $host->containerId, $host->slot, $orderedIds,
        );
        $this->markSaved();
    }

    /**
     * @param  list<string>  $orderedIds
     */
    public function reorderChild(string $parentBlockId, string $slot, array $orderedIds): void
    {
        $this->guardWritable();

        if (! $this->blockBelongsToHost($parentBlockId)) {
            return;
        }

        foreach ($orderedIds as $id) {
            if (TgsBlock::isLockedForCurrentUser($id)) {
                return;
            }
        }

        app(CanvasPersistence::class)->reorderSlot('block', $parentBlockId, $slot, $orderedIds);
        $this->markSaved();
    }

    /**
     * Слайс E (фронтенд DnD между слотами): единственный обработчик кросс-слотового переноса —
     * reorderRoot()/reorderChild() выше остаются нетронутыми и продолжают обслуживать
     * внутри-слотовый случай (Alpine различает их по `$event.from === $event.to`, см.
     * tgs-canvas.blade.php/_node.blade.php). `$fromContainerType`/`$toContainerType` — `'block'`
     * для слота дочернего блока, любое другое значение (тип хоста канваса) для корневого слота —
     * ровно то же различение, что уже используют addChildBlock()/reorderChild() выше.
     *
     * Ownership-гейты мирроят duplicateChildBlock(): перемещаемый блок и оба контейнера-блока
     * (если они есть) обязаны принадлежать смонтированному хосту — иначе перенос затронул бы
     * общее поддерево, невидимое отсюда (та же защита, что уже стоит на удалении/дублировании).
     * Доменные проверки (циклы, allowedChildren, лок) — внутри CanvasPersistence::moveBlock(),
     * оно же возвращает false на отказ; markSaved()/уведомление об изменении дерева вызывается
     * только при реальном перемещении, чтобы отклонённый дроп не показывал ложный "сохранено".
     */
    public function moveBlockAcrossSlots(
        string $blockId,
        string $fromContainerType,
        string $fromContainerId,
        string $fromSlot,
        string $toContainerType,
        string $toContainerId,
        string $toSlot,
        ?string $afterBlockId,
        ?string $beforeBlockId,
    ): void {
        $this->guardWritable();

        if (! $this->blockBelongsToHost($blockId) || TgsBlock::isLockedForCurrentUser($blockId)) {
            return;
        }

        if ($fromContainerType === 'block' && ! $this->blockBelongsToHost($fromContainerId)) {
            return;
        }

        if ($toContainerType === 'block' && ! $this->blockBelongsToHost($toContainerId)) {
            return;
        }

        $host = $this->host();
        $moved = app(CanvasPersistence::class)->moveBlock(
            $host, $host,
            $fromContainerType, $fromContainerId, $fromSlot,
            $blockId,
            $toContainerType, $toContainerId, $toSlot,
            $afterBlockId, $beforeBlockId,
        );

        if ($moved) {
            $this->markSaved();
        }
    }

    public function lockBlockAction(): Action
    {
        return Action::make('lockBlockAction')
            ->requiresConfirmation()
            ->modalHeading(__('tgs::filament.collections.lock_heading'))
            ->modalDescription(__('tgs::filament.collections.lock_description'))
            ->modalSubmitActionLabel(__('tgs::filament.collections.lock'))
            ->action(function (array $arguments): void {
                $this->guardWritable();

                $this->lockBlock($arguments['block']);
            });
    }

    public function unlockBlockAction(): Action
    {
        return Action::make('unlockBlockAction')
            ->requiresConfirmation()
            ->modalHeading(__('tgs::filament.collections.unlock_heading'))
            ->modalSubmitActionLabel(__('tgs::filament.collections.unlock'))
            ->action(function (array $arguments): void {
                $this->guardWritable();

                $this->unlockBlock($arguments['block']);
            });
    }

    /**
     * Task 3 (2a-1): "удаление" чужого блока — семантика уже была unlink (deleteBlock() в
     * CanvasPersistence снимает placement, не трогая чужой оригинал) — модалка теперь
     * называет это явно ("Убрать со страницы"), чтобы не выглядеть как настоящее удаление
     * общего блока.
     *
     * Слайс C (Этап 3): когда блок используется больше чем в одном месте
     * (`usagePlacesCount() > 1`), бинарный "удалить"/"убрать" недостаточен — модалка
     * показывает Radio-выбор из двух вариантов:
     * - `force` — `CanvasPersistence::forceDeleteEverywhere()`, физически везде;
     * - `unlink` (по умолчанию) — сегодняшнее поведение без изменений, только это
     *   размещение, блок осиротевает (`deleteRootBlock()`/`deleteChildBlock()`).
     * При одном месте — как раньше: schema() пуста, действует `unlink`.
     *
     * Третий вариант ("отсоединить как копию") здесь НЕ показывается — по коду он ничего не
     * удаляет: он берёт ЭТО ЖЕ размещение и переключает его на независимый клон, а сам блок
     * остаётся видимым ровно на этом же месте (оригинал и остальные, ДРУГИЕ места, где он
     * используется, не затрагиваются вообще). Семантически это "сделать отсюда независимым",
     * а не "удалить" — и живёт отдельным `detachBlockAction()` (кнопка на узле), не в этой
     * модалке, чтобы не путать пользователя ожиданием, что блок пропадёт со страницы.
     *
     * Этап 4, Слайс E (Требование 4.1): когда удаляемый блок — контейнер с хотя бы одним живым
     * потомком (`nestedLiveDescendantsCount() > 0`), заголовок называет число вложенных блоков
     * ("Удалить «Аккордеон» и 23 вложенных блока?") — лист/пустой контейнер получают заголовок
     * без изменений (без числа), как и раньше. Это ДОПОЛНЯЕТ, а не заменяет 3-вариантную модалку
     * шаренных блоков (Этап 3): когда блок одновременно и шаренный (choice-модалка с Radio), и
     * контейнерный — заголовок остаётся choice.heading (выбор варианта важнее для UX, там же
     * решается судьба ВСЕХ вложенных), а число вложенных добавляется отдельной строкой в
     * description, так что оба факта видны в одной модалке одновременно.
     */
    public function deleteBlockAction(): Action
    {
        return Action::make('deleteBlockAction')
            ->requiresConfirmation()
            ->color('danger')
            ->modalHeading(function (array $arguments): string {
                $blockId = $arguments['block'];

                if ($this->offersDeleteEverywhere($blockId)) {
                    return __('tgs::filament.tgs.canvas.delete.choice.heading');
                }

                $nested = $this->nestedLiveDescendantsCount($blockId);
                if ($nested > 0) {
                    return trans_choice('tgs::filament.tgs.canvas.delete.container_heading', $nested, [
                        'name' => $this->blockDisplayLabel($blockId),
                        'count' => $nested,
                    ]);
                }

                return $this->isForeignBlock($blockId)
                    ? __('tgs::filament.tgs.canvas.delete.unlink_heading')
                    : __('tgs::filament.tgs.canvas.delete.heading');
            })
            ->modalDescription(function (array $arguments): string {
                $blockId = $arguments['block'];
                $nested = $this->nestedLiveDescendantsCount($blockId);

                if ($this->offersDeleteEverywhere($blockId)) {
                    $description = __('tgs::filament.tgs.canvas.delete.choice.description');

                    return $nested > 0
                        ? $description.' '.trans_choice('tgs::filament.tgs.canvas.delete.container_nested_note', $nested, ['count' => $nested])
                        : $description;
                }

                if ($nested > 0) {
                    return __('tgs::filament.tgs.canvas.delete.description');
                }

                return $this->isForeignBlock($blockId)
                    ? __('tgs::filament.tgs.canvas.delete.unlink_description')
                    : __('tgs::filament.tgs.canvas.delete.description');
            })
            ->modalSubmitActionLabel(fn (array $arguments): string => $this->isForeignBlock($arguments['block'])
                ? __('tgs::filament.tgs.canvas.delete.unlink_submit')
                : __('tgs::filament.tgs.canvas.delete.submit'))
            ->schema(fn (array $arguments): array => $this->offersDeleteEverywhere($arguments['block'])
                ? [
                    Radio::make('deleteChoice')
                        ->label(__('tgs::filament.tgs.canvas.delete.choice.field_label'))
                        ->options([
                            'unlink' => __('tgs::filament.tgs.canvas.delete.choice.unlink.label'),
                            ...(Gate::allows('tgs.deleteEverywhere') ? ['force' => __('tgs::filament.tgs.canvas.delete.choice.force.label')] : []),
                        ])
                        ->descriptions([
                            'unlink' => __('tgs::filament.tgs.canvas.delete.choice.unlink.description'),
                            'force' => __('tgs::filament.tgs.canvas.delete.choice.force.description'),
                        ])
                        ->default('unlink')
                        ->required(),
                ]
                : [])
            ->action(function (array $data, array $arguments): void {
                $this->guardWritable();

                $blockId = $arguments['block'];

                if (TgsBlock::isLockedForCurrentUser($blockId)) {
                    return;
                }

                $parent = $arguments['parent'] ?? null;
                if (($data['deleteChoice'] ?? null) === 'force') {
                    Gate::authorize('tgs.deleteEverywhere');
                }
                $choice = $this->offersDeleteEverywhere($blockId) ? ($data['deleteChoice'] ?? 'unlink') : 'unlink';

                if ($choice === 'force') {
                    Gate::authorize('tgs.deleteEverywhere');
                    // Hotfix-срез 1, баг 2: block id приходит от клиента — «удалить везде» только
                    // с канваса, где блок реально стоит в указанном месте. Владение уже проверено
                    // при выборе $choice.
                    abort_unless($this->isPlacedAt($blockId, $parent, $arguments['slot'] ?? null), 403);

                    app(CanvasPersistence::class)->forceDeleteEverywhere($blockId);
                    $this->markSaved();

                    return;
                }

                if ($parent === null) {
                    $this->deleteRootBlock($blockId);
                } else {
                    $this->deleteChildBlock($parent, (string) $arguments['slot'], $blockId);
                }
            });
    }

    /**
     * Hotfix-срез 1, баг 2: «удалить везде» предлагается только для блока, которым владеет
     * смонтированный хост (или сироты — `blockBelongsToHost()` считает её своей) и который
     * используется больше чем в одном месте. Чужой блок (библиотечный, со страницы другого
     * хоста) с этого канваса можно только убрать со страницы — удалять его отовсюду должен
     * владелец.
     */
    private function offersDeleteEverywhere(string $blockId): bool
    {
        return $this->usagePlacesCount($blockId) > 1 && $this->blockBelongsToHost($blockId);
    }

    /** Стоит ли `$blockId` в указанном месте ЭТОГО канваса (корень хоста или слот `$parentId`). */
    private function isPlacedAt(string $blockId, ?string $parentId, ?string $slot): bool
    {
        $host = $this->host();

        return TgsModels::placement()::query()
            ->where('block_id', $blockId)
            ->where('container_type', $parentId === null ? $host->containerType : 'block')
            ->where('container_id', $parentId ?? $host->containerId)
            ->where('slot', $parentId === null ? $host->slot : (string) $slot)
            ->exists()
            && ($parentId === null || $this->blockBelongsToHost($parentId));
    }

    /**
     * Task 3 (2a-1 "механика ссылок"): открепляет чужую ссылку в placement'е
     * `(containerType, containerId, slot)`, заменяя её на владеемый клон
     * (CanvasPersistence::detachBlock — форс-клон корня, вложенные ссылки клонируются только
     * при detachNested=true). Когда в поддереве есть вложенные ссылки (subtreeHasReferences),
     * показываем Toggle "открепить вложенные тоже" — иначе это чистый confirm-only диалог
     * (schema() пуста, requiresConfirmation() отдельно оформляет confirm-без-формы кейс).
     */
    public function detachBlockAction(): Action
    {
        return Action::make('detachBlockAction')
            ->label(__('tgs::filament.tgs.actions.detach.label'))
            ->modalHeading(__('tgs::filament.tgs.canvas.detach.heading'))
            ->modalDescription(__('tgs::filament.tgs.canvas.detach.description'))
            ->modalSubmitActionLabel(__('tgs::filament.tgs.actions.detach.label'))
            ->schema(fn (array $arguments): array => app(CanvasPersistence::class)
                ->subtreeHasReferences($this->host(), $arguments['block'])
                ? [
                    Toggle::make('detachNested')
                        ->label(__('tgs::filament.tgs.canvas.detach.nested_toggle'))
                        ->default(false),
                ]
                : [])
            ->requiresConfirmation(fn (array $arguments): bool => ! app(CanvasPersistence::class)
                ->subtreeHasReferences($this->host(), $arguments['block']))
            ->action(function (array $data, array $arguments): void {
                $this->guardWritable();

                // Ownership guard (finding #1, final review, defense in depth alongside the
                // blade gate): when containerType is 'block', containerId is the parent block —
                // if it's a nested foreign block (not owned by this host), detaching here would
                // mutate the placement inside the SHARED container itself, visible to every host
                // referencing it. The root case (containerType = the host's own type) needs no
                // such guard — detaching a root-level reference is exactly what this action is for.
                if ($arguments['containerType'] === 'block' && ! $this->blockBelongsToHost($arguments['containerId'])) {
                    return;
                }

                app(CanvasPersistence::class)->detachBlock(
                    $this->host(),
                    $arguments['containerType'],
                    $arguments['containerId'],
                    $arguments['slot'],
                    $arguments['block'],
                    (bool) ($data['detachNested'] ?? false),
                );
                $this->markSaved();
            });
    }

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
            ->fillForm(function (array $arguments): array {
                $block = TgsModels::block()::find($arguments['block'] ?? null);
                $this->blockEditOriginal = $block->data ?? [];

                // `id` isn't part of $block->data (a real Eloquent column, not a form
                // field) — but media-backed blocks (Picture/Gallery) read it via
                // Get('id') to resolve which Block row their upload attaches to
                // (TgsBlock::resolveMediaHostBlock()). Without it, $get('id') is
                // null and firstOrCreate(['id' => null], ...) violates the NOT NULL
                // constraint on blocks.id.
                return [
                    ...$this->blockEditOriginal,
                    'id' => $arguments['block'] ?? null,
                    '_name' => $block?->name,
                ];
            })
            ->schema(function (array $arguments, Schema $schema): array {
                $fields = self::blockEditSchema(
                    TgsModels::block()::find($arguments['block'] ?? null)?->type,
                    $this->isRootBlockOnEdit($arguments),
                    $this->isCollectionHost(),
                    $schema,
                );

                // Task 6/5: naming a block is only meaningful while editing on the library
                // canvas (Решение A) or a collection canvas (Task 5, shown in the collection
                // picker) — a normal page's canvas never shows this field. Instance-scoped
                // check (host()/group() need $this), so it's built here rather than threaded
                // into the static blockEditSchema(). On the library canvas specifically, only
                // ROOT blocks get a name (2026-07-23): a block nested inside a composite library
                // block isn't independently referenceable via "Из библиотеки" (see
                // getLibraryBlocksProperty()'s root-only definition of a "library block"), so
                // naming it has nothing to attach to.
                if ($this->canNameBlockOnEdit($arguments)) {
                    array_unshift($fields, TextInput::make('_name')
                        ->label(__('tgs::filament.tgs.canvas.library.name_field.label'))
                        ->helperText(__('tgs::filament.tgs.canvas.library.name_field.helper'))
                        ->maxLength(255));
                }

                return $fields;
            })
            ->modalHeading(function (array $arguments): string {
                $label = $this->blockMeta($arguments['type'] ?? '')['label'];
                $block = TgsModels::block()::find($arguments['block'] ?? null);
                $key = match (true) {
                    // Read-only design (§3, Task 4): same action, same schema — in read-only it
                    // opens for viewing only, so the heading must say so rather than "edit".
                    $this->readonly => 'tgs::filament.tgs.canvas.block_modal.view',
                    $block !== null && $block->committed_at === null => 'tgs::filament.tgs.canvas.block_modal.create',
                    default => 'tgs::filament.tgs.canvas.block_modal.edit',
                };

                return __($key, ['block' => $label]);
            })
            // Этап 3, Слайс B ("где используется"): passive info only, not a blocking
            // confirmation — a shared block's editor still opens on a single click, this just
            // tells the editor how many OTHER places would see the change. Hidden (returns
            // null -> Filament's getModalDescription() falls through to no description) for a
            // brand-new draft (no block id yet) and for a block used in only one place, where
            // "used in 1 place" would be noise, not information.
            ->modalDescription(function (array $arguments): ?string {
                $blockId = $arguments['block'] ?? null;
                if ($blockId === null) {
                    return null;
                }

                $count = $this->usagePlacesCount($blockId);

                return $count > 1
                    ? trans_choice('tgs::filament.tgs.canvas.block_modal.usage_note', $count, ['count' => $count])
                    : null;
            })
            // Read-only design (§2/§3, Task 4): disabling the schema container is enough — it
            // propagates DOWN to every nested field/tab/repeater/media-uploader without touching
            // any block class (Filament's CanBeDisabled::isDisabled() checks the container
            // chain). This governs only what the modal RENDERS; it is not the enforcement
            // boundary (Filament explicitly documents disabled() as UI-only, not security) — the
            // actual guard is guardWritable() in the action() closure below, untouched by Task 4.
            ->disabledSchema(fn (): bool => $this->readonly)
            // No submit path in view mode (§3): returning false from this closure makes
            // getModalSubmitAction() return null, so the modal's footer offers only the cancel/
            // close action. In normal mode the closure returns null, leaving Filament's default
            // submit action untouched.
            ->modalSubmitAction(fn (): ?bool => $this->readonly ? false : null)
            ->action(function (array $data, array $arguments, Schema $schema): void {
                $this->guardWritable();

                if (TgsBlock::isLockedForCurrentUser($arguments['block'] ?? null)) {
                    return;
                }

                // Task 6 fix: `_name` is a form-only field for the real `name` column, not
                // part of the block's `data` JSON blob — strip it before $data is persisted
                // via commitDraft/editBlockData, or it leaks into `data` (and propagates into
                // future copyBlockInto clones). Whitespace-only input is treated as empty too.
                $name = trim((string) ($data['_name'] ?? ''));
                unset($data['_name']);

                $block = TgsModels::block()::query()->lockForUpdate()->find($arguments['block']);
                $wasDraft = $block !== null && $block->committed_at === null;
                if ($block !== null) {
                    $data = app(BlockEditData::class)->merge($block->data, $data, $schema, $this->blockEditItems);
                }

                if ($wasDraft) {
                    // ADR-020: черновик — сабмит коммитит и размещает атомарно.
                    app(CanvasPersistence::class)->commitDraft(
                        $this->host(),
                        $block->id,
                        $this->draftPlacement['containerType'],
                        $this->draftPlacement['containerId'],
                        $this->draftPlacement['slot'],
                        $this->draftPlacement['position'],
                        $data,
                    );
                    $this->draftBlockId = null;
                } else {
                    app(CanvasPersistence::class)->editBlockData($this->host(), $arguments['block'], $data);
                }

                // Task 6/5: `name` is a real column, written separately from the `data` JSON
                // blob — mirrors the addToLibraryAction() write path. Only reachable when the
                // `_name` field was actually in the schema (canNameBlockOnEdit()), but guard
                // again here since $data is client-suppliable.
                if ($this->canNameBlockOnEdit($arguments)) {
                    app(CanvasPersistence::class)->renameBlock($this->host(), $arguments['block'], $name);
                }

                $this->markSaved();

                if ($wasDraft) {
                    $this->notifyBlockCreated();
                } else {
                    $this->notify(__('tgs::filament.tgs.canvas.notifications.block_updated'));
                }
            });
    }

    /**
     * ADR-020: закрытие/отмена модалки без сабмита. Если открытая модалка была для черновика
     * (created но не committed — $draftBlockId ещё не обнулён commitDraft()'ом в
     * editBlockAction), это отмена создания — черновик не должен пережить закрытие модалки,
     * удаляем его немедленно (медиа чистится deleting-событием модели). Правка уже committed
     * блока никогда не выставляет $draftBlockId (см. addRootBlock/addChildBlock/
     * quickAddChild vs. прямой mountAction('editBlockAction', ...) без doDraft-аргументов),
     * поэтому обычная отмена правки существующего блока сюда не попадает.
     */
    public function unmountAction(bool $canCancelParentActions = true): void
    {
        if ($this->draftBlockId !== null) {
            $draft = TgsModels::block()::find($this->draftBlockId);

            if ($draft !== null && $draft->committed_at === null) {
                $draft->delete();
            }

            $this->draftBlockId = null;
        }

        $this->traitUnmountAction($canCancelParentActions);
    }

    private function markSaved(): void
    {
        $this->savedAt = now()->format('H:i:s');
    }

    private function notifyBlockCreated(): void
    {
        $this->notify(__('tgs::filament.tgs.canvas.notifications.block_created'));
    }

    private function notify(string $title): void
    {
        Notification::make()
            ->title($title)
            ->success()
            ->send();
    }

    /**
     * Hotfix-срез 1 (после ручной проверки бага 1): вставка в залоченный родитель отклоняется не
     * молча — редактор видит, почему кнопка «ничего не сделала». true = родитель залочен для
     * текущего пользователя (уведомление уже отправлено), вызывающий прекращает вставку.
     */
    private function refuseLockedParent(string $parentBlockId): bool
    {
        if (! TgsBlock::isLockedForCurrentUser($parentBlockId)) {
            return false;
        }

        Notification::make()
            ->title(__('tgs::filament.tgs.canvas.notifications.parent_locked'))
            ->body(__('tgs::filament.tgs.canvas.notifications.parent_locked_body'))
            ->warning()
            ->send();

        return true;
    }

    /**
     * Read-only design (§2/§3): the single enforcement point for every mutating entry point —
     * called as the first statement of each mutating method and of each mutating Action's
     * action() closure. abort(403), not a silent no-op: if the UI doesn't offer the action in
     * read-only, a direct call here is either a bug or a bypass attempt, and either way must
     * surface rather than be swallowed.
     */
    private function guardWritable(): void
    {
        abort_if($this->readonly || $this->isCanvasMissing(), 403);
        TgsAuthorization::authorizeCanvas($this->canvas());
    }

    public function canManageLocks(): bool
    {
        return TgsBlock::currentUserCanManageLocks();
    }

    /**
     * Состояние лока блока для блейда (plan 2b, §12): effectivelyLocked — статус-индикатор
     * (бейдж, всем ролям, admin НЕ байпасит); lockedForUser — гейт ограничений (disabled
     * поля/кнопки, admin байпасит → false); directlyLocked — прямой флаг (какую кнопку
     * показывать: Заблокировать vs Разблокировать).
     *
     * @return array{effectivelyLocked: bool, directlyLocked: bool, lockedForUser: bool}
     */
    public function lockState(string $blockId): array
    {
        return [
            'effectivelyLocked' => TgsBlock::isBlockEffectivelyLocked($blockId),
            'directlyLocked' => TgsBlock::isBlockLocked($blockId),
            'lockedForUser' => TgsBlock::isLockedForCurrentUser($blockId),
        ];
    }

    /**
     * Подсказка для лок-бейджа: объясняет менеджеру, ЧТО значит лок и ПОЧЕМУ узел
     * заблокирован — напрямую или каскадом от родителя. null, если не заблокирован.
     * Без расширения данных: только is_locked/effective через lockState().
     */
    public function lockHint(string $blockId): ?string
    {
        $state = $this->lockState($blockId);

        if (! $state['effectivelyLocked']) {
            return null;
        }

        $consequence = 'Редактирование, удаление и перемещение недоступны.';

        return $state['directlyLocked']
            ? "Блок заблокирован. {$consequence}"
            : "Заблокирован родительским блоком. {$consequence}";
    }

    /**
     * Метаданные типа блока для рендера узла (иконка/подпись) — Task 9, plan B2. Тонкая
     * обёртка над describeBlock(), не трогает CanvasTreeReader (структурное дерево остаётся
     * id/type/preview/slots).
     *
     * @return array{label: string, icon: string|\BackedEnum|Htmlable|null, layout: string}
     */
    public function blockMeta(string $type): array
    {
        $class = ContentBuilderFactory::blockClassFor($type);
        if ($class === null) {
            return ['label' => $type, 'icon' => null, 'layout' => TgsBlockLayout::Stack->value];
        }

        $meta = self::describeBlock($class);

        return ['label' => $meta['label'], 'icon' => $meta['icon'], 'layout' => $meta['layout']];
    }

    /**
     * Modal fields from the descriptor. Empty general tabs are omitted;
     * heading/section are optional. IDs and access checks stay outside the schema.
     * Reused by OrphanedBlocksTable, including for existing hidden block types.
     *
     * @return list<mixed>
     */
    public static function blockEditSchema(?string $type, bool $isRoot, bool $isCollectionHost, Schema $schema): array
    {
        $descriptor = $type !== null ? app(TgsBlockRegistry::class)->get($type) : null;
        if ($descriptor === null) {
            return [];
        }
        $tabs = [];
        $dataFields = $descriptor->schema();
        if ($dataFields !== []) {
            $tabs[] = Tab::make('general')
                ->label(__('tgs::filament.tgs.settings.tabs.data.label'))
                ->schema($dataFields);
        }
        if ($descriptor->hasHeadingFields()) {
            $tabs[] = Tab::make('heading')
                ->label(__('tgs::filament.tgs.settings.tabs.heading.label'))
                ->schema($descriptor->fields('getBlockHeadingFields'));
        }
        if ($descriptor->hasSectionFields()) {
            $tabs[] = Tab::make('section')
                ->label(__('tgs::filament.tgs.settings.tabs.section.label'))
                ->schema($descriptor->fields('getBlockSectionFields'));
        }
        if ($isRoot) {
            $tabs[] = Tab::make('container')
                ->label(__('tgs::filament.tgs.settings.tabs.container.label'))
                ->schema($descriptor->fields('getBlockContainerFields'));
        }
        $fields = $tabs !== [] ? [Tabs::make()->tabs($tabs)] : [];

        if ($isCollectionHost) {
            self::relaxOptionalFieldsInCollection($fields, $type, $schema);
        }

        return $fields;
    }

    /**
     * Task (2026-07-23): mutates the already-built $fields tree in place — reusing the SAME
     * Schema instance Filament already built for this mounted action (statePath
     * "mountedActions.{n}.data", see InteractsWithActions::getMountedActionSchema()), passed
     * in from the ->schema() closure's injected `Schema $schema` parameter, rather than a
     * fresh throwaway Schema::make(). getKey() on a leaf field caches its ABSOLUTE key
     * (HasKey::$cachedAbsoluteKey) the first time it's computed and never recomputes it — a
     * throwaway Schema (no/incomplete statePath) permanently poisons that cache with the
     * wrong key, silently dropping the field from the submitted data later. Assigning
     * $fields onto the real $schema here is harmless/idempotent: Filament's own
     * HasSchema::getSchema() calls $schema->components($fields) again right after this
     * closure returns.
     *
     * @param  list<mixed>  $fields
     */
    private static function relaxOptionalFieldsInCollection(array $fields, ?string $type, Schema $schema): void
    {
        $class = $type !== null ? ContentBuilderFactory::blockClassFor($type) : null;
        if ($class === null) {
            return;
        }

        $optionalFields = $class::make()->blockOptionalFieldsInCollection();
        if ($optionalFields === []) {
            return;
        }

        $flatFields = $schema->components($fields)->getFlatComponents();

        foreach ($optionalFields as $fieldName) {
            $field = $flatFields[$fieldName] ?? null;

            if ($field instanceof Field) {
                $field->required(false);
            }
        }
    }

    public function render(): View
    {
        if ($this->isCanvasMissing()) {
            return view('tgs::tgs-canvas-missing');
        }

        return view('tgs::tgs-canvas');
    }
}
