<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Services;

use ApiMedia\Tgs\Enums\BlockLockMode;
use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockDeletion;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsModels;
use ExileOfAranei\ListOrdering\Support\GroupKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function array_fill;
use function array_is_list;
use function array_keys;
use function array_map;
use function array_shift;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;

/**
 * Персистентность канваса (ADR-019): операции над blocks+placements.
 * Сохраняет ownership-инварианты
 * (чужие блоки — только ссылки, их не трогаем).
 */
class CanvasPersistence
{
    /**
     * The single `(container_type, container_id, slot)` triple as the package's `GroupKey` —
     * matches `\ApiMedia\Tgs\Support\TgsModels::placement()::orderingGroupColumns()` exactly, required by `placeInto()`'s
     * internal assertion.
     */
    private function group(string $containerType, string $containerId, string $slot): GroupKey
    {
        return GroupKey::of([
            'container_type' => $containerType,
            'container_id' => $containerId,
            'slot' => $slot,
        ]);
    }

    /**
     * Resolves the legacy "insert at integer index N" contract onto the package's anchor-based
     * `placeInto()`: the new placement lands immediately before whichever placement currently
     * sits at index `$position` (0-based, current rank order) — the same "existing occupant and
     * everything after it shift right" semantics the old two-phase position rewrite produced,
     * except the package only computes one fresh rank between two neighbours instead of
     * rewriting every sibling row. `$position === null`, or an index the group doesn't have
     * (e.g. `>= count`), means "append at the end" — `placeAtEnd()`.
     */
    private function placeAtOptionalIndex(BlockPlacement $placement, GroupKey $group, ?int $position): void
    {
        $before = $position !== null
            ? TgsModels::placement()::query()->ordered($group)->skip($position)->first()
            : null;

        if ($before !== null) {
            $placement->placeInto($group, null, $before);

            return;
        }

        $placement->placeAtEnd($group);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addBlock(
        HostDescriptor $host,
        string $containerType,
        string $containerId,
        string $slot,
        string $blockType,
        array $data,
        ?int $position = null,
    ): Block {
        return DB::transaction(function () use ($host, $containerType, $containerId, $slot, $blockType, $data, $position): Block {
            app(TgsInsertionPolicy::class)->ensureDestination($blockType, $containerType, $containerId, $slot, $host);
            $block = $this->createCommittedBlock($host, $blockType, $data);

            $placement = new (TgsModels::placement())(['block_id' => $block->id]);
            $this->placeAtOptionalIndex($placement, $this->group($containerType, $containerId, $slot), $position);

            $this->signalChanged($host, [$block->id]);

            return $block;
        });
    }

    /** @param array<string, mixed> $data */
    private function createCommittedBlock(HostDescriptor $host, string $type, array $data, ?string $id = null): Block
    {
        return TgsModels::block()::forceCreate([
            'id' => $id ?? (string) Str::ulid(),
            'type' => $type,
            'data' => $data,
            'owner_container_type' => $host->containerType,
            'owner_container_id' => $host->containerId,
            'committed_at' => now(),
        ]);
    }

    /**
     * Trusted snapshot import for seeders/fixtures. Replaces placements, retaining block
     * identities and unplaced rows; foreign blocks are references, never overwritten.
     * UI insertion eligibility is intentionally not applied to historical seed snapshots.
     *
     * @param  array<int, mixed>  $tree
     */
    public function replaceTree(HostDescriptor $host, array $tree): void
    {
        DB::transaction(function () use ($host, $tree): void {
            $this->adoptSnapshotOrphans($host, $tree);
            $this->clearSnapshotPlacements($host);
            $changed = [];
            $this->writeSnapshotSlot($host, $host->containerType, $host->containerId, $host->slot, $tree, $changed);
            $this->signalChanged($host, $changed);
        });
    }

    /**
     * Adopt before clearing: an orphan container already present in the old tree must
     * have its old child placements replaced too. Foreign subtrees stay opaque.
     *
     * @param  array<int, mixed>  $tree
     */
    private function adoptSnapshotOrphans(HostDescriptor $host, array $tree): void
    {
        foreach ($tree as $node) {
            if (! is_array($node) || ! isset($node['type'], $node['data'])
                || ! is_string($node['type']) || ! is_array($node['data'])) {
                continue;
            }
            $block = isset($node['data']['id'])
                ? TgsModels::block()::find((string) $node['data']['id'])
                : null;
            if ($block !== null) {
                if ($block->owner_container_type === 'orphan') {
                    $block->update(['owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId]);
                } elseif ($block->owner_container_type !== $host->containerType || $block->owner_container_id !== $host->containerId) {
                    continue;
                }
            }
            $data = $node['data'];
            foreach ($this->takeSnapshotChildren($data) as $children) {
                $this->adoptSnapshotOrphans($host, $children);
            }
        }
    }

    /** Remove placement groups only, stopping traversal at foreign ownership boundaries. */
    private function clearSnapshotPlacements(HostDescriptor $host): void
    {
        $queue = [[$host->containerType, $host->containerId, $host->slot]];
        $visited = [];
        while ($queue !== []) {
            [$type, $id, $slot] = array_shift($queue);
            $query = TgsModels::placement()::query()
                ->where('container_type', $type)->where('container_id', $id);
            if ($slot !== null) {
                $query->where('slot', $slot);
            }
            $childIds = $query->pluck('block_id')->all();
            $query->delete();
            foreach ($childIds as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $child = TgsModels::block()::find($childId);
                if ($child !== null && $child->owner_container_type === $host->containerType
                    && $child->owner_container_id === $host->containerId) {
                    $queue[] = ['block', $childId, null];
                }
            }
        }
    }

    /**
     * @param  array<int, mixed>  $tree
     * @param  list<string>  $changed
     */
    private function writeSnapshotSlot(HostDescriptor $host, string $type, string $id, string $slot, array $tree, array &$changed): void
    {
        foreach ($tree as $node) {
            if (! is_array($node) || ! isset($node['type'], $node['data'])
                || ! is_string($node['type']) || ! is_array($node['data'])) {
                continue;
            }
            $blockId = (string) ($node['data']['id'] ?? Str::ulid());
            $block = TgsModels::block()::find($blockId);
            $owned = $block === null || $block->owner_container_type === 'orphan'
                || ($block->owner_container_type === $host->containerType && $block->owner_container_id === $host->containerId);
            if ($owned) {
                $data = $node['data'];
                unset($data['id']);
                $children = $this->takeSnapshotChildren($data);
                if ($block === null) {
                    $block = $this->createCommittedBlock($host, $node['type'], $data, $blockId);
                } else {
                    $block->update([
                        'type' => $node['type'], 'data' => $data,
                        'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId,
                    ]);
                }
                $this->pruneOrphanedMedia($block, $data);
                foreach ($children as $childSlot => $childTree) {
                    $this->writeSnapshotSlot($host, 'block', $blockId, $childSlot, $childTree, $changed);
                }
            }
            $placement = new (TgsModels::placement())(['block_id' => $blockId]);
            $this->placeAtOptionalIndex($placement, $this->group($type, $id, $slot), null);
            $changed[] = $blockId;
        }
    }

    /**
     * Separate direct and items.i.field child slots from a snapshot node's data.
     * Empty/scalar lists remain data, matching the persisted snapshot contract.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, mixed>>
     */
    private function takeSnapshotChildren(array &$data): array
    {
        $children = [];
        foreach ($data as $key => $value) {
            if ($this->isSnapshotBlockList($value)) {
                $children[(string) $key] = $value;
                unset($data[$key]);
            } elseif (is_array($value) && array_is_list($value)) {
                foreach ($value as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    foreach ($item as $field => $nested) {
                        if ($this->isSnapshotBlockList($nested)) {
                            $children["{$key}.{$index}.{$field}"] = $nested;
                            unset($data[$key][$index][$field]);
                        }
                    }
                }
            }
        }

        return $children;
    }

    private function isSnapshotBlockList(mixed $value): bool
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $node) {
            if (! is_array($node) || ! isset($node['type'], $node['data'])
                || ! is_string($node['type']) || ! is_array($node['data'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A reference (`insertReference()`) reuses the SAME `block_id` at a new placement — so
     * inserting $blockId as a reference into its own subtree (or directly into itself) would
     * make a block contain itself. `copyBlockInto()` never needs this: a copy always gets a
     * brand-new id via `copySubtree()`'s deep clone, so a copy can never equal one of its own
     * ancestors. BFS down $blockId's own descendants (bounded by a visited-set, safe even if
     * the data already has an unrelated cycle) rather than walking UP from the container,
     * because a shared/referenced block can have MULTIPLE parent placements — there's no
     * single "the" ancestor chain to walk, but $blockId's own subtree is unambiguous.
     */
    public function wouldCreateCycle(string $blockId, string $containerType, string $containerId): bool
    {
        if ($containerType !== 'block') {
            return false;
        }

        if ($containerId === $blockId) {
            return true;
        }

        $visited = [$blockId => true];
        $frontier = [$blockId];

        while ($frontier !== []) {
            $children = TgsModels::placement()::query()
                ->where('container_type', 'block')
                ->whereIn('container_id', $frontier)
                ->pluck('block_id')
                ->all();

            $frontier = [];

            foreach ($children as $childId) {
                if ($childId === $containerId) {
                    return true;
                }

                if (! isset($visited[$childId])) {
                    $visited[$childId] = true;
                    $frontier[] = $childId;
                }
            }
        }

        return false;
    }

    /**
     * Вставляет уже существующий блок как ссылку: общий placement на чужой `block_id`,
     * без клонирования и без смены владельца блока (в отличие от `duplicateBlock()`).
     * Возвращает null, если блок с `$blockId` не существует.
     */
    public function insertReference(
        HostDescriptor $host,
        string $containerType,
        string $containerId,
        string $slot,
        string $blockId,
        ?int $position = null,
    ): ?BlockPlacement {
        return DB::transaction(function () use ($host, $containerType, $containerId, $slot, $blockId, $position): ?BlockPlacement {
            // Этап 4: a soft-deleted block sits in the trash — reject inserting a reference to
            // it exactly like a non-existent block, rather than resurrecting it as a live
            // placement behind the trash bin's back.
            if (TgsModels::block()::query()->whereNull('deleted_at')->find($blockId) === null) {
                return null;
            }

            app(TgsInsertionPolicy::class)->ensureSubtree($blockId, $containerType, $containerId, $slot, $host);

            if ($this->wouldCreateCycle($blockId, $containerType, $containerId)) {
                return null;
            }

            $placement = new (TgsModels::placement())(['block_id' => $blockId]);
            $this->placeAtOptionalIndex($placement, $this->group($containerType, $containerId, $slot), $position);

            $this->signalChanged($host, [$blockId]);

            return $placement;
        });
    }

    /**
     * Создаёт черновой блок (ADR-020): блок без placement, `committed_at = NULL`. Владелец —
     * хост. Невидим для read-моделей за счёт отсутствия placement, а не global scope.
     * Финализируется через `commitDraft()`.
     */
    public function createDraftBlock(HostDescriptor $host, string $type): Block
    {
        app(TgsInsertionPolicy::class)->ensureAvailable($type, $host);
        $block = TgsModels::block()::create([
            'id' => (string) Str::ulid(),
            'type' => $type,
            'data' => [],
            'owner_container_type' => $host->containerType,
            'owner_container_id' => $host->containerId,
            'committed_at' => null,
        ]);

        $this->signalChanged($host, [$block->id]);

        return $block;
    }

    /**
     * Финализирует черновик из `createDraftBlock()`: размещает его в слоте (через
     * `placeAtOptionalIndex()`, как в `addBlock()`) и одновременно проставляет `committed_at` +
     * записывает `data`. Всё в одной транзакции — черновик становится видимым и содержательным
     * атомарно.
     *
     * @param  array<string, mixed>  $data
     */
    public function commitDraft(
        HostDescriptor $host,
        string $blockId,
        string $containerType,
        string $containerId,
        string $slot,
        ?int $position,
        array $data,
    ): void {
        DB::transaction(function () use ($host, $blockId, $containerType, $containerId, $slot, $position, $data): void {
            $draft = TgsModels::block()::findOrFail($blockId);
            app(TgsInsertionPolicy::class)->ensureSubtree($draft->id, $containerType, $containerId, $slot, $host);
            $placement = new (TgsModels::placement())(['block_id' => $blockId]);
            $this->placeAtOptionalIndex($placement, $this->group($containerType, $containerId, $slot), $position);

            $block = TgsModels::block()::find($blockId);
            $block?->forceFill([
                'committed_at' => now(),
                'data' => $data,
            ])->save();

            if ($block !== null) {
                $this->pruneOrphanedMedia($block, $data);
            }

            $this->signalChanged($host, [$blockId]);
        });
    }

    /**
     * Глубокое копирование поддерева `sourceBlockId` (структура + `data`, без медиа —
     * см. Task 2) и вставка корня копии сразу после исходника в `(containerType,
     * containerId, slot)`. Чужие/переиспользуемые (synced) блоки в поддереве не
     * клонируются — копия ссылается на тот же block_id, синхрон сохраняется.
     *
     * Возвращает null, если исходник не найден, не размещён в указанном слоте, либо
     * корневой `sourceBlockId` — чужой (не принадлежит хосту и не orphan): дублировать
     * можно только собственное/orphan-поддерево хоста, а не чужой блок целиком.
     */
    public function duplicateBlock(
        HostDescriptor $host,
        string $containerType,
        string $containerId,
        string $slot,
        string $sourceBlockId,
    ): ?Block {
        return DB::transaction(function () use ($host, $containerType, $containerId, $slot, $sourceBlockId): ?Block {
            $sourcePlacement = TgsModels::placement()::query()
                ->where('container_type', $containerType)
                ->where('container_id', $containerId)
                ->where('slot', $slot)
                ->where('block_id', $sourceBlockId)
                ->first();

            if ($sourcePlacement === null) {
                return null;
            }

            $sourceBlock = TgsModels::block()::find($sourceBlockId);
            $sourceOwnedByHost = $sourceBlock !== null
                && (($sourceBlock->owner_container_type === $host->containerType
                        && $sourceBlock->owner_container_id === $host->containerId)
                    || $sourceBlock->owner_container_type === 'orphan');

            if (! $sourceOwnedByHost) {
                return null;
            }

            app(TgsInsertionPolicy::class)->ensureSubtree($sourceBlockId, $containerType, $containerId, $slot, $host);

            $cloneId = $this->copySubtree($host, $sourceBlockId, []);
            $clone = TgsModels::block()::find($cloneId);
            if ($clone === null) {
                return null;
            }

            $placement = new (TgsModels::placement())(['block_id' => $clone->id]);
            $placement->placeAfter($sourcePlacement);

            $this->signalChanged($host, [$clone->id]);

            return $clone;
        });
    }

    /**
     * Клонирует поддерево `sourceBlockId` целиком независимой копией, владеемой
     * `targetHost` (форс-клон корня + всех детей + медиа, аналог `detachBlock()`, но
     * без привязки к существующему placement источника — источник может быть чужим:
     * библиотечным/страничным). Размещает клон в `(containerType, containerId, slot)`
     * на `$position` (через `placeAtOptionalIndex()`, как в `addBlock()`).
     *
     * В отличие от `duplicateBlock()` (только собственное/orphan-поддерево хоста,
     * вложенные ссылки сохраняются), здесь корень и все вложенные блоки клонируются
     * независимо от исходного владения — используется для вставки-копией из
     * библиотеки блоков и для «добавить в библиотеку».
     *
     * Возвращает null, если `sourceBlockId` не существует.
     */
    public function copyBlockInto(
        HostDescriptor $targetHost,
        string $containerType,
        string $containerId,
        string $slot,
        string $sourceBlockId,
        ?int $position = null,
    ): ?Block {
        return DB::transaction(function () use ($targetHost, $containerType, $containerId, $slot, $sourceBlockId, $position): ?Block {
            if (TgsModels::block()::find($sourceBlockId) === null) {
                return null;
            }

            app(TgsInsertionPolicy::class)->ensureSubtree($sourceBlockId, $containerType, $containerId, $slot, $targetHost);

            $cloneId = $this->copySubtree($targetHost, $sourceBlockId, [], forceRoot: true, detachNested: true);
            $clone = TgsModels::block()::find($cloneId);
            if ($clone === null) {
                return null;
            }

            $placement = new (TgsModels::placement())(['block_id' => $clone->id]);
            $this->placeAtOptionalIndex($placement, $this->group($containerType, $containerId, $slot), $position);

            $this->signalChanged($targetHost, [$clone->id]);

            return $clone;
        });
    }

    /**
     * Открепляет ссылку `$foreignBlockId`, размещённую в `(containerType, containerId,
     * slot)`, в собственную (владеемую хостом) копию: форс-клонирует корень (даже если
     * он чужой — именно в этом смысл detach), затем клонирует владеемое поддерево как
     * `copySubtree()`. Вложенные чужие блоки внутри поддерева клонируются только если
     * `$detachNested === true`, иначе остаются ссылками (синхрон сохраняется).
     *
     * Заменяет исходный placement на placement на клон в ТОЙ ЖЕ позиции (соседей не
     * сдвигает, новый placement не создаёт). Возвращает null, если такого placement
     * (container*, slot, block_id=foreignBlockId) не существует.
     */
    public function detachBlock(
        HostDescriptor $host,
        string $containerType,
        string $containerId,
        string $slot,
        string $foreignBlockId,
        bool $detachNested = false,
    ): ?Block {
        return DB::transaction(function () use ($host, $containerType, $containerId, $slot, $foreignBlockId, $detachNested): ?Block {
            $placement = TgsModels::placement()::query()
                ->where('container_type', $containerType)
                ->where('container_id', $containerId)
                ->where('slot', $slot)
                ->where('block_id', $foreignBlockId)
                ->first();

            if ($placement === null) {
                return null;
            }

            app(TgsInsertionPolicy::class)->ensureSubtree($foreignBlockId, $containerType, $containerId, $slot, $host);

            $cloneId = $this->copySubtree($host, $foreignBlockId, [], forceRoot: true, detachNested: $detachNested);
            $clone = TgsModels::block()::find($cloneId);
            if ($clone === null) {
                return null;
            }

            // Вариант 3 удаления шаренного блока (Слайс C): "отсоединить" должно сохранять
            // историю, иначе это выглядит как "начать с нуля" вместо "перестать делить блок,
            // сохранив всё как было". Копируется только история КОРНЯ detach'а (foreignBlockId
            // сам по себе) — не вложенных детей, склонированных попутно при detachNested=true:
            // те клонируются ради изоляции поддерева, а не потому что пользователь явно попросил
            // отсоединить именно их, так что их версии сюда не относятся. Копии получают новые
            // id (autoincrement), но исходный created_at сохраняется — порядок истории у клона
            // должен читаться так же, как у оригинала.
            foreach (TgsModels::version()::query()->where('block_id', $foreignBlockId)->orderBy('id')->get() as $version) {
                $copy = new (TgsModels::version())([
                    'block_id' => $clone->id,
                    'data' => $version->data,
                    'created_by' => $version->created_by,
                ]);
                $copy->created_at = $version->created_at;
                $copy->save();
            }

            $placement->update(['block_id' => $clone->id]);

            $this->signalChanged($host, [$foreignBlockId]);

            return $clone;
        });
    }

    /**
     * BFS вверх от `$blockId` через `block_placements` до корневых (не `'block'`-контейнеров)
     * хостов — т.е. «где используется» этот конкретный блок в терминах реальных хостов
     * (страница, именованный канвас, settings-слот, ...). DAG-safe: один блок может иметь
     * несколько родителей — каждый корень возвращается один раз (дедуп по
     * containerType/containerId/slot). Публичный переиспользуемый примитив: панель
     * «где используется» вызывает его напрямую по одному блоку; `TgsContentChangeNotifier`
     * вызывает его в цикле по нескольким изменённым блокам и дедуплицирует результат сама
     * (batch fan-out для кэш-инвалидации).
     *
     * @return list<HostDescriptor>
     */
    public function resolveUsagePlaces(string $blockId, bool $liveOnly = false): array
    {
        $roots = [];
        $visited = [];
        $queue = [$blockId];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            if ($liveOnly && ! TgsModels::block()::query()->alive()->whereKey($current)->exists()) {
                continue;
            }

            $placements = TgsModels::placement()::query()
                ->where('block_id', $current)
                ->get(['container_type', 'container_id', 'slot']);

            foreach ($placements as $placement) {
                if ($placement->container_type === 'block') {
                    $queue[] = $placement->container_id;

                    continue;
                }

                $key = "{$placement->container_type}\0{$placement->container_id}\0{$placement->slot}";
                $roots[$key] = new HostDescriptor(
                    $placement->container_type,
                    $placement->container_id,
                    $placement->slot,
                );
            }
        }

        return array_values($roots);
    }

    /**
     * Живые `BlockPlacement`-строки на любой блок из `$blockIds`, которые НЕ являются
     * собственными внутренними рёбрами этого же набора (`container_type = 'block' AND
     * container_id IN $blockIds` — рёбра, сохранённые внутри мягко удалённого поддерева ради
     * восстановления структуры, Слайс B, не "живое использование снаружи"). Тот же самый
     * запрос, что и приватная проверка `stillUsedElsewhere` внутри `cascadeOwnedSubtree()`, —
     * общий переиспользуемый примитив для двух потребителей: `tgs:gc-block-trash` (нужен только
     * факт "есть ли хоть одна" перед физическим удалением, Требование 8.4) и
     * `tgs:trash-stuck-report` (нужны сами строки — узнать, ГДЕ именно застряло).
     *
     * @param  list<string>  $blockIds
     * @return Collection<int, BlockPlacement>
     */
    public function livePlacementsOutsideSubtree(array $blockIds): Collection
    {
        if ($blockIds === []) {
            return new Collection;
        }

        return TgsModels::placement()::query()
            ->whereIn('block_id', $blockIds)
            ->where(function ($query) use ($blockIds): void {
                $query->where('container_type', '!=', 'block')
                    ->orWhereNotIn('container_id', $blockIds);
            })
            ->get();
    }

    /**
     * Обходит поддерево блока `$blockId` (child-размещения с `container_type = 'block'`)
     * и проверяет, есть ли внутри (не считая самого корня) чужой блок — не владеемый
     * хостом и не orphan, т.е. вложенная ссылка. Используется UI для условной модалки
     * перед detach.
     */
    public function subtreeHasReferences(HostDescriptor $host, string $blockId): bool
    {
        $visited = [];
        $queue = [$blockId];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            $childPlacements = TgsModels::placement()::query()
                ->where('container_type', 'block')
                ->where('container_id', $current)
                ->get();

            foreach ($childPlacements as $childPlacement) {
                $childId = $childPlacement->block_id;
                $child = TgsModels::block()::find($childId);
                if ($child === null) {
                    continue;
                }

                $ownedByHost = ($child->owner_container_type === $host->containerType
                        && $child->owner_container_id === $host->containerId)
                    || $child->owner_container_type === 'orphan';

                if (! $ownedByHost) {
                    return true;
                }

                if (! isset($visited[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return false;
    }

    /**
     * Этап 4, Слайс E (Требование 4.1): число ЖИВЫХ (не soft-deleted) блоков во всём поддереве
     * `$blockId`, НЕ считая сам корень — "сколько вложенных блоков пропадёт из-под этого узла"
     * для UI-подтверждения удаления контейнера. Тот же паттерн BFS-обхода вниз по
     * `BlockPlacement` (`container_type = 'block'`), что и `subtreeHasReferences()`/
     * `cascadeOwnedSubtree()`, адаптированный под "посчитать, не удалять и не разделять
     * owned/foreign" — с точки зрения пользователя оба исхода (физическое мягкое удаление
     * owned-потомка и простое отсоединение foreign-ссылки) одинаково "убирают" вложенный блок
     * из-под удаляемого контейнера, так что здесь ownership не проверяется, в отличие от
     * `cascadeOwnedSubtree()`. Лист (нет дочерних слотов/placements) и пустой контейнер
     * закономерно возвращают 0.
     */
    public function countLiveDescendants(string $blockId): int
    {
        $visited = [$blockId => true];
        $queue = [$blockId];
        $count = 0;

        while ($queue !== []) {
            $current = array_shift($queue);

            $childIds = TgsModels::placement()::query()
                ->where('container_type', 'block')
                ->where('container_id', $current)
                ->pluck('block_id')
                ->all();

            foreach ($childIds as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;

                $isAlive = TgsModels::block()::query()->whereNull('deleted_at')->find($childId) !== null;
                if (! $isAlive) {
                    continue;
                }

                $count++;
                $queue[] = $childId;
            }
        }

        return $count;
    }

    /**
     * Рекурсивно клонирует поддерево, укоренённое в `$sourceId`.
     *
     * - Чужой (не владеемый хостом и не orphan) блок НЕ клонируется — возвращается
     *   его id как есть, так что копия продолжает ссылаться на тот же shared-блок
     *   (синхрон сохранён). Исключение — корень при `$forceRoot === true` (detach):
     *   он клонируется независимо от владения.
     * - Дочерние чужие блоки клонируются только если `$detachNested === true`
     *   (только имеет смысл вместе с `$forceRoot`, см. `detachBlock()`); иначе — как
     *   обычно, остаются ссылками.
     * - Владеемый/orphan-блок клонируется (новый ulid, тот же type/data, owner=хост),
     *   затем рекурсивно клонируются все его child-placements.
     *
     * @param  array<string, bool>  $visited  cycle-guard: посещённые исходные id
     */
    private function copySubtree(
        HostDescriptor $host,
        string $sourceId,
        array $visited,
        bool $forceRoot = false,
        bool $detachNested = false,
    ): string {
        if (isset($visited[$sourceId])) {
            return $sourceId;
        }
        $visited[$sourceId] = true;

        $source = TgsModels::block()::find($sourceId);
        if ($source === null) {
            return $sourceId;
        }

        $ownedByHost = ($source->owner_container_type === $host->containerType
                && $source->owner_container_id === $host->containerId)
            || $source->owner_container_type === 'orphan';

        $shouldClone = $ownedByHost || $forceRoot || $detachNested;

        if (! $shouldClone) {
            return $sourceId;
        }

        $cloneData = $source->data;

        $sourceMedia = $source->getMedia('content_builder');

        // $newMediaUuid stays null unless we can write it into BOTH places
        // (data['uuid'] and the copied media's custom_properties.uuid) —
        // keeping the two in sync is what lets the read pipeline
        // (PictureData::resolveImage) re-associate the clone's media by
        // matching custom_properties.uuid === data['uuid']. If source data
        // has no valid uuid field, media is still copied physically (so the
        // clone owns its own MediaLibrary row instead of sharing the
        // source's), but its custom_properties.uuid is left as copied
        // (i.e. untouched) rather than rewritten to an unreachable value.
        $newMediaUuid = null;
        if ($sourceMedia->isNotEmpty() && is_array($cloneData) && isset($cloneData['uuid']) && is_string($cloneData['uuid'])) {
            $newMediaUuid = Str::random(10);
            $cloneData['uuid'] = $newMediaUuid;
        }

        $clone = TgsModels::block()::create([
            'id' => (string) Str::ulid(),
            'type' => $source->type,
            'data' => $cloneData,
            'owner_container_type' => $host->containerType,
            'owner_container_id' => $host->containerId,
        ]);
        $clone->committed_at = now();
        $clone->saveQuietly();

        if ($sourceMedia->isNotEmpty()) {
            foreach ($sourceMedia as $media) {
                $copied = $media->copy($clone, 'content_builder');
                if ($newMediaUuid !== null) {
                    $copied->setCustomProperty('uuid', $newMediaUuid);
                    $copied->save();
                }
            }
        }

        $childPlacements = TgsModels::placement()::query()
            ->where('container_type', 'block')
            ->where('container_id', $sourceId)
            ->ordered()
            ->get();

        foreach ($childPlacements as $childPlacement) {
            $childCloneId = $this->copySubtree($host, $childPlacement->block_id, $visited, detachNested: $detachNested);

            // forceCreate(), not create(): rank isn't fillable (only ever meant to be written
            // through placeInto()'s guarded path) — this is the one legitimate exception, copying
            // a child's rank VERBATIM into a brand-new, independent (containerType='block',
            // containerId=$clone->id) group, which can never already contain a row (the clone
            // was just created above), so there's no existing rank in that group to collide with.
            TgsModels::placement()::forceCreate([
                'container_type' => 'block',
                'container_id' => $clone->id,
                'slot' => $childPlacement->slot,
                'rank' => $childPlacement->rank,
                'block_id' => $childCloneId,
            ]);
        }

        return $clone->id;
    }

    /**
     * Применяет полный целевой порядок слота за один вызов (используется
     * `TgsCanvas::reorderRoot()`/`reorderChild()`, которые уже получают ПОЛНЫЙ список
     * block_id для слота после drag-and-drop, а не единичное перемещение).
     *
     * Заменяет прежний `moveWithinSlot(blockId, newPosition)` — тот идентифицировал
     * "нужный" placement по одному лишь block_id, что стало неоднозначным и небезопасным,
     * когда один и тот же блок может занимать НЕСКОЛЬКО позиций в одном слоте (вставка
     * "Ссылкой" по токену обратно в тот же слот, где уже лежит источник, см. #39 в
     * docs/starter-backlog.md) — общий UPDATE по block_id попадал сразу в обе строки.
     *
     * Дубликаты block_id в `$orderedBlockIds` разрешаются стабильным сопоставлением:
     * n-е вхождение id забирает n-й ОСТАВШИЙСЯ placement с этим id (в порядке текущих
     * позиций) — это единственная однозначная интерпретация, которую вообще можно
     * извлечь из плоского списка id без per-instance идентичности с фронта (см. #39).
     * Placement, отсутствующий в `$orderedBlockIds` (штатно не должно случаться —
     * защитный случай), дописывается в конец в исходном относительном порядке, чтобы
     * ничего не потерялось молча.
     *
     * @param  list<string>  $orderedBlockIds
     */
    public function reorderSlot(string $containerType, string $containerId, string $slot, array $orderedBlockIds): void
    {
        DB::transaction(function () use ($containerType, $containerId, $slot, $orderedBlockIds): void {
            $group = $this->group($containerType, $containerId, $slot);

            $currentOrder = TgsModels::placement()::query()->ordered($group)->get(['id', 'block_id', 'container_type', 'container_id', 'slot', 'rank']);

            $queues = [];
            foreach ($currentOrder as $placement) {
                $queues[$placement->block_id][] = $placement;
            }

            $targetOrder = [];
            foreach ($orderedBlockIds as $blockId) {
                if (! empty($queues[$blockId] ?? [])) {
                    $targetOrder[] = array_shift($queues[$blockId]);
                }
            }
            foreach ($queues as $remaining) {
                foreach ($remaining as $placement) {
                    $targetOrder[] = $placement;
                }
            }

            if ($targetOrder === []) {
                return;
            }

            $this->applyTargetOrder($group, $currentOrder->all(), $targetOrder);

            $this->signalChanged(new HostDescriptor($containerType, $containerId, $slot), $orderedBlockIds);
        });
    }

    /**
     * Adapter from "here is the slot's full desired order" (what the frontend's
     * `sortable.toArray()` sends) onto the package's single-anchor `placeInto()` (validated
     * on the discarded Stage 0 spike branch, `ListOrderingSpikeTest::reorderSlotViaPackage()`,
     * for the single-moved-element case — this generalises it to an arbitrary permutation).
     *
     * Diffs $currentOrder against $targetOrder to find the LONGEST run of placements whose
     * relative order already matches the target (a longest-increasing-subsequence over each
     * placement's target index) — those never need touching, since neither their rank nor
     * their neighbours change. Only the complement gets repositioned, walking $targetOrder
     * left to right and anchoring each moved placement with `placeAfter()` on whichever
     * placement (kept or already-moved) was just finalised at the previous position — each
     * anchor resolves against the state `placeInto()` just wrote, not a stale snapshot, so the
     * chain always lands in exactly $targetOrder's order regardless of how many elements move
     * at once. A typical single-item drag moves exactly one placement (the rest is one LIS);
     * nothing here assumes that, though — it holds for any permutation.
     *
     * @param  list<BlockPlacement>  $currentOrder  Placements as currently ranked (ascending).
     * @param  list<BlockPlacement>  $targetOrder  Same placements, permuted into the desired order.
     */
    private function applyTargetOrder(GroupKey $group, array $currentOrder, array $targetOrder): void
    {
        $targetIndexById = [];
        foreach ($targetOrder as $index => $placement) {
            $targetIndexById[$placement->id] = $index;
        }

        $n = count($currentOrder);
        $targetPositions = array_map(static fn (BlockPlacement $placement): int => $targetIndexById[$placement->id], $currentOrder);

        $lisLength = array_fill(0, $n, 1);
        $predecessor = array_fill(0, $n, -1);
        for ($i = 1; $i < $n; $i++) {
            for ($j = 0; $j < $i; $j++) {
                if ($targetPositions[$j] < $targetPositions[$i] && $lisLength[$i] < $lisLength[$j] + 1) {
                    $lisLength[$i] = $lisLength[$j] + 1;
                    $predecessor[$i] = $j;
                }
            }
        }

        $end = 0;
        for ($i = 1; $i < $n; $i++) {
            if ($lisLength[$i] > $lisLength[$end]) {
                $end = $i;
            }
        }

        $kept = [];
        for ($i = $end; $i !== -1; $i = $predecessor[$i]) {
            $kept[$currentOrder[$i]->id] = true;
        }

        $previous = null;
        foreach ($targetOrder as $placement) {
            if (isset($kept[$placement->id])) {
                $previous = $placement;

                continue;
            }

            if ($previous === null) {
                $placement->placeAtStart($group);
            } else {
                $placement->placeAfter($previous);
            }

            $previous = $placement;
        }
    }

    /**
     * New primitive (Этап 2, слайс D): moves an EXISTING placement of `$blockId` (identified by
     * its current `(fromContainerType, fromContainerId, fromSlot)`, the same disambiguation
     * `duplicateBlock()`/`detachBlock()`/`extractToLibrary()` already use — a block can occupy
     * several placements at once, so `$blockId` alone never uniquely identifies the row to move)
     * to `(toContainerType, toContainerId, toSlot)`, optionally anchored next to `$afterBlockId`/
     * `$beforeBlockId` in the destination slot (first matching placement there, same "good enough"
     * resolution `reorderSlot()`'s anchors use). One `placeInto()` call does the actual work —
     * the package treats "reorder within a list" and "move to a different list" identically.
     *
     * For same-slot reordering, `reorderSlot()` already exists and is NOT replaced by this method
     * (plan decision: don't risk regressing the working in-slot path) — this is exclusively for
     * a CHANGE of `(container_type, container_id, slot)`.
     *
     * Runs the three domain checks the package deliberately leaves out (§4.5.5 ТЗ), in order,
     * short-circuiting on the first failure with a `false` return (never throws — mirrors
     * `insertReference()`/`duplicateBlock()` returning null on a refused mutation, translated to
     * bool since there's no new entity to hand back here):
     *
     *  1. The source placement must exist (`$blockId` really sits at `(fromContainerType,
     *     fromContainerId, fromSlot)` right now).
     *  2. The block must not be locked for the current user (`TgsBlock::isLockedForCurrentUser()`).
     *  3. Moving `$blockId` (with its subtree) into `$toContainerId` must not create a cycle
     *     (`wouldCreateCycle()` — a no-op unless `$toContainerType === 'block'`).
     *  4. The destination slot must accept `$blockId`'s type — `BlockChildPolicy::allowedChildrenFor()`
     *     against the destination container block, when `$toContainerType === 'block'` (root-level
     *     canvas slots have no such per-type restriction, same as everywhere else this policy is
     *     consulted). Applies uniformly to leaf AND container blocks moved as-is — e.g. a `tab_item`
     *     may only land in a `tabs`/`accordion` block's `items` slot.
     *
     * `$fromHost`/`$toHost` are signalled explicitly (rather than relying on `signalChanged()`'s
     * own reverse-lookup) because after the move `$blockId`'s placement no longer resolves back to
     * `$fromHost` — the old root would otherwise never learn its tree changed. Signalling both,
     * even when they're the same host (same-canvas cross-slot move), is harmless (over-signalling
     * is the documented tradeoff `signalChanged()` already accepts).
     */
    public function moveBlock(
        HostDescriptor $fromHost,
        HostDescriptor $toHost,
        string $fromContainerType,
        string $fromContainerId,
        string $fromSlot,
        string $blockId,
        string $toContainerType,
        string $toContainerId,
        string $toSlot,
        ?string $afterBlockId = null,
        ?string $beforeBlockId = null,
    ): bool {
        return DB::transaction(function () use (
            $fromHost, $toHost, $fromContainerType, $fromContainerId, $fromSlot,
            $blockId, $toContainerType, $toContainerId, $toSlot, $afterBlockId, $beforeBlockId,
        ): bool {
            $placement = TgsModels::placement()::query()
                ->where('container_type', $fromContainerType)
                ->where('container_id', $fromContainerId)
                ->where('slot', $fromSlot)
                ->where('block_id', $blockId)
                ->first();

            if ($placement === null) {
                return false;
            }

            if (TgsBlock::isLockedForCurrentUser($blockId)) {
                return false;
            }

            if ($this->wouldCreateCycle($blockId, $toContainerType, $toContainerId)) {
                return false;
            }

            if (TgsModels::block()::find($blockId) === null) {
                return false;
            }
            try {
                app(TgsInsertionPolicy::class)->ensureSubtree($blockId, $toContainerType, $toContainerId, $toSlot, $toHost);
            } catch (ValidationException) {
                return false;
            }

            if (! $this->slotAcceptsBlockType($toContainerType, $toContainerId, $toSlot, $blockId)) {
                return false;
            }

            $group = $this->group($toContainerType, $toContainerId, $toSlot);

            $after = $afterBlockId !== null
                ? TgsModels::placement()::query()
                    ->where('container_type', $toContainerType)
                    ->where('container_id', $toContainerId)
                    ->where('slot', $toSlot)
                    ->where('block_id', $afterBlockId)
                    ->first()
                : null;

            $before = $beforeBlockId !== null
                ? TgsModels::placement()::query()
                    ->where('container_type', $toContainerType)
                    ->where('container_id', $toContainerId)
                    ->where('slot', $toSlot)
                    ->where('block_id', $beforeBlockId)
                    ->first()
                : null;

            if ($after !== null) {
                $placement->placeInto($group, $after, null);
            } elseif ($before !== null) {
                $placement->placeInto($group, null, $before);
            } else {
                $placement->placeAtEnd($group);
            }

            $this->signalChanged($fromHost, [$blockId]);
            $this->signalChanged($toHost, [$blockId]);

            return true;
        });
    }

    /**
     * Effective allowedChildren check for a would-be destination slot, shared by `moveBlock()`.
     * Root-level slots (`$containerType !== 'block'`) have no such restriction — `allowedChildren`
     * is a concept of a container BLOCK's declared slots only (mirrors `availableBlocksFor()`'s
     * own "no containerClass resolved → unrestricted" fallback below).
     */
    private function slotAcceptsBlockType(string $containerType, string $containerId, string $slot, string $blockId): bool
    {
        if ($containerType !== 'block') {
            return true;
        }

        $container = TgsModels::block()::find($containerId);
        if ($container === null) {
            return false;
        }

        $block = TgsModels::block()::find($blockId);
        if ($block === null) {
            return false;
        }

        $containerClass = ContentBuilderFactory::blockClassFor($container->type);
        if ($containerClass === null) {
            return true;
        }

        $allowed = app(BlockChildPolicy::class)->allowedChildrenFor($containerClass::make(), $slot);

        return $allowed === [] || in_array($block->type, $allowed, true);
    }

    public function deleteBlock(
        HostDescriptor $host,
        string $containerType,
        string $containerId,
        string $slot,
        string $blockId,
    ): void {
        DB::transaction(function () use ($host, $containerType, $containerId, $slot, $blockId): void {
            // Этап 4, Слайс B: capture the root's positional anchor (rank-neighbours in the
            // slot it's about to be unlinked from) BEFORE the placement row disappears below —
            // there's nothing left to derive it from afterwards. A single transaction_id (one
            // ULID) is minted here, once per delete call, regardless of how large the owned
            // subtree cascadeOwnedSubtree() ends up walking — "one operation, one journal row".
            $targetPlacement = TgsModels::placement()::query()
                ->ordered(GroupKey::of([
                    'container_type' => $containerType,
                    'container_id' => $containerId,
                    'slot' => $slot,
                ]))
                ->where('block_id', $blockId)
                ->first();

            $anchor = $targetPlacement !== null ? $this->anchorForPlacement($targetPlacement) : null;

            // Removing this one edge is all it takes — fractional ranks need no compaction of
            // the remaining siblings afterwards (unlike the old dense-integer `position`, which
            // required renumbering everything after the removed row).
            TgsModels::placement()::query()
                ->where('container_type', $containerType)
                ->where('container_id', $containerId)
                ->where('slot', $slot)
                ->where('block_id', $blockId)
                ->delete();

            $this->cascadeOwnedSubtree($host, $blockId, (string) Str::ulid(), $this->resolveCanvasId($host), $anchor);

            $this->signalChanged($host, [$blockId]);
        });
    }

    /**
     * Восстановление из корзины (Этап 4, Слайс C). Резолвит СТРОГО по `BlockDeletion.id`
     * (Приложение А §3.2.3) — НИКОГДА обходом по `parent_block_id`/дереву: если внутри того же
     * поддерева позже случилось ЕЩЁ одно, более позднее удаление (свой собственный
     * `transaction_id`), у затронутых им блоков будет ДРУГОЙ `deleted_op_id` — массовый UPDATE
     * ниже фильтрует строго по `transaction_id` ЭТОЙ записи и их не трогает. Это главный
     * инвариант метода: восстановление родителя не воскрешает отдельно удалённого потомка.
     *
     * Алгоритм (план §5, Приложение А §5):
     *  1. Снять `deleted_at`/`deleted_op_id` со всех блоков этой операции (корень + все
     *     потомки, помеченные тем же `transaction_id`) — один mass UPDATE, не рекурсия по
     *     дереву.
     *  2. Заново создать `BlockPlacement` корня — исходный placement был физически удалён ДО
     *     каскада (см. `deleteBlock()`/`forceDeleteEverywhere()` выше, там же и минтится
     *     `transaction_id`), воссоздавать заново, а не искать старую строку. Позиция — из
     *     `payload`-якоря: живой `prev_sibling`, всё ещё размещённый в том же слоте →
     *     `placeAfter`-эквивалент; иначе живой `next_sibling` на месте → `placeBefore`-эквивалент;
     *     иначе — конец исходного слота. Мёртвый/переставленный сосед В ПРЕДЕЛАХ того же
     *     исходного слота — это НЕ fallback с точки зрения UI: слот не меняется, просто якорь
     *     съезжает на другого живого соседа/конец списка.
     *  3. Если исходный родитель — блок-контейнер, но он либо сам недостижим (soft-deleted и
     *     ещё не восстановлен), либо больше не принимает этот тип блока в этом слоте
     *     (`BlockChildPolicy::allowedChildrenFor()` через тот же `slotAcceptsBlockType()`,
     *     что и `moveBlock()`) — восстановление уходит в корень канваса (`\ApiMedia\Tgs\Support\TgsModels::canvas()::hostDescriptor()`
     *     по `canvas_id` записи) вместо исходного слота. Это и ТОЛЬКО это считается «позиция
     *     отличается от исходной» — второй элемент возврата, материал для тоста Слайса D
     *     (Требование 5.4). Родитель, физически недостижимый потому что сам ещё в корзине,
     *     естественно попадает в эту же ветку (\ApiMedia\Tgs\Support\TgsModels::block()::find под `whereNull('deleted_at')`
     *     его не находит) — отдельной защиты сверх этого не требуется (план явно это оговаривает).
     *
     * `forceDeleteEverywhere()`-путь стирал СРАЗУ все размещения блока — восстанавливает этот
     * метод только ОДИН представительный placement (на месте первого прямого размещения на
     * момент удаления, ровно то же самое, что `forceDeleteEverywhere()` использовало как якорь
     * для журнала). Множественные использования шаренного блока, снятые тем удалением, не
     * воссоздаются — осознанное ограничение Этапа 4, не баг.
     *
     * Возврат — `null`, если восстанавливать нечего: запись не найдена, ЛИБО корень уже не
     * несёт этот `transaction_id` (повторный вызов на уже восстановленную или невалидную
     * запись) — осознанный идемпотентный no-op, зеркалит `insertReference()`/`duplicateBlock()`/
     * `moveBlock()` (возврат `null`/`false` на отклонённую мутацию вместо исключения), НИКОГДА
     * не 500. Иначе — воссозданный корневой `BlockPlacement` и `positionMatched` (см. пункт 3).
     *
     * @return array{placement: BlockPlacement, positionMatched: bool}|null
     */
    public function restoreBlock(string $deletionId): ?array
    {
        return DB::transaction(function () use ($deletionId): ?array {
            $deletion = TgsModels::deletion()::find($deletionId);
            if ($deletion === null) {
                return null;
            }

            $transactionId = $deletion->transaction_id;

            $root = TgsModels::block()::query()
                ->where('id', $deletion->block_id)
                ->where('deleted_op_id', $transactionId)
                ->first();

            if ($root === null) {
                // Already restored (idempotent repeat call) — or an id whose block no longer
                // carries this transaction_id for any other reason. No-op either way.
                return null;
            }

            TgsModels::block()::query()->where('deleted_op_id', $transactionId)->update([
                'deleted_at' => null,
                'deleted_op_id' => null,
            ]);

            /** @var array<string, mixed> $payload */
            $payload = $deletion->payload;
            $targetContainerType = is_string($payload['parent_container_type'] ?? null) ? $payload['parent_container_type'] : null;
            $targetContainerId = is_string($payload['parent_container_id'] ?? null) ? $payload['parent_container_id'] : null;
            $targetSlot = is_string($payload['slot'] ?? null) ? $payload['slot'] : null;
            $prevSibling = is_string($payload['prev_sibling'] ?? null) ? $payload['prev_sibling'] : null;
            $nextSibling = is_string($payload['next_sibling'] ?? null) ? $payload['next_sibling'] : null;

            $positionMatched = true;
            $targetResolved = $targetContainerType !== null && $targetContainerId !== null && $targetSlot !== null;

            if ($targetResolved && $targetContainerType === 'block') {
                $parentAlive = TgsModels::block()::query()->whereNull('deleted_at')->find($targetContainerId) !== null;
                $targetResolved = $parentAlive
                    && $this->slotAcceptsBlockType($targetContainerType, $targetContainerId, $targetSlot, $root->id);
            }

            // Этап 4 assumes a BlockDeletion's canvas always still exists (a deleted canvas
            // takes its block_deletions rows down with it — GC/Слайс D's list filter never
            // hands restoreBlock() one that doesn't) — resolved eagerly since both the
            // fallback branch below AND the final signalChanged() need it.
            $canvasHost = $this->hostDescriptorForCanvas($deletion->canvas);

            if (! $targetResolved) {
                $targetContainerType = $canvasHost->containerType;
                $targetContainerId = $canvasHost->containerId;
                $targetSlot = $canvasHost->slot;
                $prevSibling = null;
                $nextSibling = null;
                $positionMatched = false;
            }

            $prevPlacement = $this->resolveLivingSiblingPlacement($targetContainerType, $targetContainerId, $targetSlot, $prevSibling);
            $nextPlacement = $this->resolveLivingSiblingPlacement($targetContainerType, $targetContainerId, $targetSlot, $nextSibling);

            $group = $this->group($targetContainerType, $targetContainerId, $targetSlot);
            $placement = new (TgsModels::placement())(['block_id' => $root->id]);

            if ($prevPlacement !== null) {
                $placement->placeInto($group, $prevPlacement, null);
            } elseif ($nextPlacement !== null) {
                $placement->placeInto($group, null, $nextPlacement);
            } else {
                $placement->placeAtEnd($group);
            }

            $this->signalChanged($canvasHost, [$root->id]);

            return ['placement' => $placement, 'positionMatched' => $positionMatched];
        });
    }

    /**
     * Живой (не soft-deleted) сосед по рангу, всё ещё размещённый ровно в целевом слоте —
     * иначе `restoreBlock()` не может доверять id соседа, сохранённому в payload, как якорю
     * (сосед мог сам уехать в корзину, или переехать в другой слот тем временем DnD).
     */
    private function resolveLivingSiblingPlacement(string $containerType, string $containerId, string $slot, ?string $siblingBlockId): ?BlockPlacement
    {
        if ($siblingBlockId === null) {
            return null;
        }

        $isAlive = TgsModels::block()::query()->whereNull('deleted_at')->find($siblingBlockId) !== null;
        if (! $isAlive) {
            return null;
        }

        return TgsModels::placement()::query()
            ->where('container_type', $containerType)
            ->where('container_id', $containerId)
            ->where('slot', $slot)
            ->where('block_id', $siblingBlockId)
            ->first();
    }

    /**
     * Вариант 1 удаления шаренного блока (Слайс C, Этап 3): удаляет `$blockId` в корзину (с
     * Этапа 4 — мягкое удаление через `cascadeOwnedSubtree()`, не физическое) и снимает ВСЕ его
     * `BlockPlacement`-строки — не одно размещение, как `deleteBlock()`, а везде, во всех хостах,
     * которые на него ссылаются — плюс каскадно владеемых детей его собственного поддерева
     * (переиспользует `cascadeOwnedSubtree()`, не дублирует BFS). Журнал корзины хранит один
     * якорь (первое прямое размещение), поэтому «Вернуть» восстанавливает блок только туда —
     * остальные размещения теряются (бэклог #47). Вызывающий отвечает за то, что удаляет
     * владелец: `TgsCanvas` предлагает это только для собственного блока (hotfix-срез 1, баг 2).
     *
     * Блок владеет ОДНИМ `owner_container_type`/`owner_container_id` (placements — это лишь
     * ссылки на него, владение не per-placement), поэтому передавать в `cascadeOwnedSubtree()`
     * нужно host, построенный из фактического владельца блока, а не из одного из мест
     * использования — это гарантирует, что "ownedByHost" внутри неё всегда истинно для корня и
     * каскад доходит до конца, а не молча переоткрепляет блок на orphan (та ветка рассчитана на
     * "блок пережил ОДНО удаляемое размещение", здесь же удаляются сразу ВСЕ размещения).
     *
     * Целиком в `DB::transaction()`: частичное удаление (например часть placements снята, а
     * каскад упал на середине) хуже полного отката — атомарность важна для операции, которую
     * корзина откатывает лишь частично.
     */
    public function forceDeleteEverywhere(string $blockId): void
    {
        DB::transaction(function () use ($blockId): void {
            $block = TgsModels::block()::find($blockId);
            if ($block === null) {
                return;
            }

            // resolveUsagePlaces() читает block_placements — единственный шанс узнать, кого
            // уведомить о пропаже блока, ДО того как размещения будут удалены ниже (после
            // удаления блок больше нигде не резолвится, notify() сможет сигналить только тому
            // $host, который ему явно передан).
            $roots = $this->resolveUsagePlaces($blockId);

            $ownerHost = new HostDescriptor($block->owner_container_type, $block->owner_container_id, '');

            // Этап 4, Слайс B: same anchor/journal capture as deleteBlock(), adapted for "every
            // placement disappears at once" — the block's OWN first direct placement (by id,
            // deterministic) stands in as the representative anchor (there's no single "the"
            // location once every reference is severed simultaneously); canvas_id prefers a real
            // usage root over the (possibly synthetic 'orphan') owner host, for the same reason.
            $directPlacements = $block->placements()->orderBy('id')->get();
            $anchor = $directPlacements->isNotEmpty() ? $this->anchorForPlacement($directPlacements->first()) : null;
            $canvasId = $roots !== [] ? $this->resolveCanvasId($roots[0]) : $this->resolveCanvasId($ownerHost);

            $block->placements()->delete();

            $this->cascadeOwnedSubtree($ownerHost, $blockId, (string) Str::ulid(), $canvasId, $anchor);

            if ($roots === []) {
                $this->signalChanged($ownerHost, [$blockId]);
            } else {
                foreach ($roots as $root) {
                    $this->signalChanged($root, [$blockId]);
                }
            }
        });
    }

    /**
     * Task 2 (tgs-canvas as entities, extract to library): atomically promotes a block that
     * currently lives on a page/host into the shared library, replacing the original
     * placement with a reference to the library copy at the SAME position — the page's
     * shape doesn't shift. The library copy is always self-contained (`copyBlockInto()`
     * already does forceRoot+detachNested via `copySubtree()`) — no separate "ask about
     * nested" branch is needed here.
     *
     * Sequencing matters: the source's preceding sibling (by rank) MUST be captured before
     * `deleteBlock()` removes the source placement, then reused as the anchor for the new
     * reference's `placeAfter()`/`placeAtStart()` call — never re-derived afterwards (the
     * sibling itself is untouched by the delete, so no `fresh()` is needed). The whole sequence
     * runs in one transaction (nested `DB::transaction()` calls from the reused primitives use
     * savepoints, so a failure anywhere rolls everything back, never leaving an orphaned
     * library copy or a vanished original).
     *
     * Returns null if the source placement doesn't exist, or if `copyBlockInto()` couldn't
     * clone the source block (e.g. it was deleted concurrently).
     */
    public function extractToLibrary(
        HostDescriptor $libraryHost,
        HostDescriptor $pageHost,
        string $containerType,
        string $containerId,
        string $slot,
        string $sourceBlockId,
        ?string $name,
    ): ?Block {
        return DB::transaction(function () use ($libraryHost, $pageHost, $containerType, $containerId, $slot, $sourceBlockId, $name): ?Block {
            $sourcePlacement = TgsModels::placement()::query()
                ->where('container_type', $containerType)
                ->where('container_id', $containerId)
                ->where('slot', $slot)
                ->where('block_id', $sourceBlockId)
                ->first();

            if ($sourcePlacement === null) {
                return null;
            }

            $group = $this->group($containerType, $containerId, $slot);

            // Captured before deleteBlock() removes the source row: the sibling immediately
            // preceding it by rank, if any. Reused below to reinsert the new reference at the
            // SAME spot the original occupied — no manual position bookkeeping needed, unlike
            // the old dense-integer `position` this replaced.
            $previousSibling = TgsModels::placement()::query()
                ->ordered($group)
                ->where('rank', '<', $sourcePlacement->rank)
                ->orderByDesc('rank')
                ->first();

            $clone = $this->copyBlockInto(
                $libraryHost, $libraryHost->containerType, $libraryHost->containerId, $libraryHost->slot, $sourceBlockId,
            );

            if ($clone === null) {
                return null;
            }

            if ($name !== null && $name !== '') {
                $clone->name = $name;
                $clone->save();
            }

            $this->deleteBlock($pageHost, $containerType, $containerId, $slot, $sourceBlockId);

            $reference = $this->insertReference($pageHost, $containerType, $containerId, $slot, $clone->id);

            if ($reference !== null) {
                if ($previousSibling !== null) {
                    $reference->placeAfter($previousSibling);
                } else {
                    $reference->placeAtStart($group);
                }
            }

            $this->signalChanged($pageHost, [$sourceBlockId]);

            return $clone;
        });
    }

    /**
     * Осиротевшие блоки (owner_container_type='orphan', \ApiMedia\Tgs\Support\TgsModels::block()::unclaimedOwner()): даёт уже
     * существующему блоку владельца (библиотека), НЕ клонируя его и НЕ трогая ни одно из его
     * текущих размещений — в отличие от extractToLibrary(), рассчитанного на "заменить ОДНО
     * каноническое размещение ссылкой на клон", у осиротевшего блока такого канонического
     * слота нет (он мог накопить несколько мест использования уже без единого "текущего"), так
     * что здесь блок просто ДОПОЛНИТЕЛЬНО получает новое размещение в конце корня библиотеки,
     * поверх всех существующих.
     *
     * Идемпотентно: не-orphan блок (уже усыновлён, либо id не существует) — no-op, `null`.
     */
    public function adoptOrphanIntoLibrary(string $blockId): ?BlockPlacement
    {
        return DB::transaction(function () use ($blockId): ?BlockPlacement {
            $block = TgsModels::block()::find($blockId);

            if ($block === null || $block->owner_container_type !== 'orphan') {
                return null;
            }

            $libraryHost = TgsModels::canvas()::named(TgsNamedCanvas::BlockLibrary)->hostDescriptor();

            app(TgsInsertionPolicy::class)->ensureSubtree($blockId, $libraryHost->containerType, $libraryHost->containerId, $libraryHost->slot, $libraryHost);

            $group = $this->group($libraryHost->containerType, $libraryHost->containerId, $libraryHost->slot);
            $placement = new (TgsModels::placement())(['block_id' => $blockId]);
            $placement->placeAtEnd($group);

            $block->update([
                'owner_container_type' => $libraryHost->containerType,
                'owner_container_id' => $libraryHost->containerId,
            ]);

            $this->signalChanged($libraryHost, [$blockId]);

            return $placement;
        });
    }

    /**
     * §12 (plan 2b) + hotfix-срез 1, баг 7: ставит/снимает лок блока. Лок из UI всегда каскадный
     * (surface-режим не выставляется, как в старом Builder); снятие трогает только `is_locked`.
     * Авторизация (кто может управлять локами, владение блоком) — забота вызывающего.
     */
    public function setLock(HostDescriptor $host, string $blockId, bool $locked): void
    {
        $block = TgsModels::block()::find($blockId);
        if ($block === null) {
            return;
        }

        $block->update($locked
            ? ['is_locked' => true, 'lock_mode' => BlockLockMode::Cascade]
            : ['is_locked' => false]);

        $this->signalChanged($host, [$blockId]);
    }

    /**
     * Hotfix-срез 1, баг 7: `name` — реальная колонка, пишется отдельно от `data` (Task 6/5:
     * имя блока в библиотеке/коллекции). Пустая строка нормализуется в null.
     */
    public function renameBlock(HostDescriptor $host, string $blockId, ?string $name): void
    {
        $block = TgsModels::block()::find($blockId);
        if ($block === null) {
            return;
        }

        $block->name = $name !== '' ? $name : null;
        $block->save();

        $this->signalChanged($host, [$blockId]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function editBlockData(HostDescriptor $host, string $blockId, array $data): void
    {
        $block = TgsModels::block()::find($blockId);
        if ($block === null) {
            return;
        }

        $ownedByHost = ($block->owner_container_type === $host->containerType
                && $block->owner_container_id === $host->containerId)
            || $block->owner_container_type === 'orphan';

        if (! $ownedByHost) {
            return;
        }

        // §13: snapshot the OLD data before it's overwritten — the only trigger point for
        // block history. Skipped when data is unchanged (no-op modal submit) to avoid noise.
        if ($block->data !== $data) {
            TgsModels::version()::create([
                'block_id' => $block->id,
                'data' => $block->data,
                'created_by' => auth()->id(),
                // Явно, а не полагаясь на миграции `created_at`'s DB-level `useCurrent()` —
                // тот же класс бага, что и был у `block_deletions.created_at` (Postgres-сессия
                // в Europe/Moscow, `config('app.timezone')` — UTC; Eloquent читал московское
                // время обратно как будто уже UTC, история "уезжала" на ~3 часа в будущее).
                'created_at' => now(),
            ]);
        }

        $block->update(['data' => $data]);

        $this->pruneOrphanedMedia($block, $data);

        $this->signalChanged($host, [$blockId]);
    }

    /**
     * §13: restores a block's `data` to a prior snapshot. Reuses editBlockData() wholesale —
     * inherits its ownership guard, media reconciliation, and (being itself a data overwrite)
     * automatically versions the pre-revert state, so reverting a revert works for free.
     */
    public function revertToVersion(HostDescriptor $host, string $blockId, int $versionId): bool
    {
        $version = TgsModels::version()::query()
            ->where('id', $versionId)
            ->where('block_id', $blockId)
            ->first();

        if ($version === null) {
            return false;
        }

        $this->editBlockData($host, $blockId, $version->data);

        $this->signalChanged($host, [$blockId]);

        return true;
    }

    /**
     * Fan out a TGS content-change signal so cache-invalidation (and live-canvas refresh) fire for
     * this host AND any other host referencing the changed blocks. Called last in every mutating
     * method — enforced by CanvasPersistenceSignalsChangeTest. Over-signalling is harmless.
     *
     * @param  list<string>  $changedBlockIds
     */
    private function signalChanged(HostDescriptor $host, array $changedBlockIds): void
    {
        app(TgsContentChangeNotifier::class)->notify($host, $changedBlockIds);
    }

    /**
     * Общая логика реконсиляции медиа для editBlockData()/commitDraft(): чистит осиротевшие
     * media-загрузки блока, не совпадающие с текущим data['uuid'] (листовые media-блоки)
     * или data['images'] (галереи).
     *
     * @param  array<string, mixed>  $data
     */
    private function pruneOrphanedMedia(Block $block, array $data): void
    {
        if (isset($data['uuid']) && is_string($data['uuid'])) {
            $block->pruneContentMediaExcept([$data['uuid']]);
        } elseif (isset($data['images']) && is_array($data['images'])) {
            $block->pruneContentMediaExcept($data['images']);
        }
    }

    /**
     * Позиционный якорь одного placement'а (Этап 4, Слайс B) — rank-соседи в его собственном
     * `(container_type, container_id, slot)`-слоте, снятые ДО того, как сам placement исчезнет.
     * `parent_container_type`/`parent_container_id` вместе со `slot` полностью адресуют слот
     * (root-level placement — containerType — это тип хоста, а не `'block'`; nested — всегда
     * `'block'` + id родительского блока), а `prev_sibling`/`next_sibling` — id блоков-соседей
     * по рангу (null, если сосед отсутствует) — ровно форма `{parent, slot, prev_sibling,
     * next_sibling}` из плана Этапа 4.
     *
     * @return array{parent_container_type: string, parent_container_id: string, slot: string, prev_sibling: ?string, next_sibling: ?string}
     */
    private function anchorForPlacement(BlockPlacement $placement): array
    {
        $prev = TgsModels::placement()::query()
            ->where('container_type', $placement->container_type)
            ->where('container_id', $placement->container_id)
            ->where('slot', $placement->slot)
            ->where('rank', '<', $placement->rank)
            ->orderByDesc('rank')
            ->value('block_id');

        $next = TgsModels::placement()::query()
            ->ordered(GroupKey::of([
                'container_type' => $placement->container_type,
                'container_id' => $placement->container_id,
                'slot' => $placement->slot,
            ]))
            ->where('rank', '>', $placement->rank)
            ->value('block_id');

        return [
            'parent_container_type' => $placement->container_type,
            'parent_container_id' => $placement->container_id,
            'slot' => $placement->slot,
            'prev_sibling' => $prev,
            'next_sibling' => $next,
        ];
    }

    /**
     * Резолвит `canvases.id` для журнала (Этап 4, Слайс B): `'canvas'`-хосты (named/embedded,
     * см. `HostDescriptor::forCanvas()`) уже несут id канваса как свой `containerId` напрямую;
     * любой другой (host-kind) хост лениво получает/заводит соответствующую строку `canvases`
     * тем же паттерном, что и `HasCanvases::canvasGroups()`-автосоздание на `created`, но без
     * ограничения на объявленные группы — `deleteBlock()`/`forceDeleteEverywhere()` обязаны
     * резолвить канвас для ЛЮБОГО хоста, который им передают (в т.ч. легаси
     * `content_builder_value`-слоты вне списка `canvasGroups()`), а не только для официально
     * задекларированных.
     *
     * ОСТАЁТСЯ private — `firstOrCreate()` — это запись, а не чтение. Читающим путям (Слайс D:
     * TgsCanvas/TgsTrashDrawer, рендер, никогда не должен иметь побочных эффектов) нужен
     * {@see findCanvasId()}, не этот метод — см. его докблок за тем, ПОЧЕМУ звать
     * `resolveCanvasId()` из рендера — баг, эмпирически подтверждённый
     * `PageTemplateCanvasMountTest`'s «no stray duplicate Canvas row» при разработке Слайса D
     * (вызов из простого get-компьютед-свойства на каждый рендер заводил ВТОРУЮ, теневую строку
     * `canvases` для любого хоста через `HasCanvases`/`canvasGroups()` — см. `findCanvasId()`).
     */
    private function resolveCanvasId(HostDescriptor $host): int
    {
        if ($host->containerType === 'canvas') {
            return (int) $host->containerId;
        }

        return TgsModels::canvas()::query()->firstOrCreate([
            'host_type' => $host->containerType,
            'host_id' => $host->containerId,
            'group' => $host->slot,
            'kind' => 'host',
        ])->id;
    }

    /**
     * Read-only inverse companion to {@see resolveCanvasId()} (Этап 4, Слайс D): та же
     * where-триплетка (`host_type`/`host_id`/`group`/`kind`), но `->value('id')` вместо
     * `firstOrCreate()` — НИКОГДА не пишет строку `canvases`. `block_deletions.canvas_id`
     * всегда пишется через `resolveCanvasId()` (внутри `deleteBlock()`/`forceDeleteEverywhere()`,
     * настоящая мутация) — если для хоста ни разу не резолвился, значит для него физически не
     * может существовать ни одной строки `block_deletions`, и `null` здесь ровно то же самое,
     * что "канвас-корзина пуста", без необходимости заводить строку заранее.
     *
     * `HasCanvases`'s `morphMany(\ApiMedia\Tgs\Support\TgsModels::canvas(), 'host')` без зарегистрированного `Relation::
     * morphMap()` пишет `host_type` полным FQCN класса модели, тогда как `HostDescriptor::
     * forModelSlot()` (и, соответственно, этот метод) всегда использует snake_case алиас — те
     * же самые host_type/host_id/group логически, но РАЗНОЕ строковое значение host_type,
     * значит и РАЗНАЯ строка `canvases`. TgsCanvas/TgsTrashDrawer обязаны звать именно этот
     * метод, а не `resolveCanvasId()` напрямую и не `\ApiMedia\Tgs\Support\TgsModels::canvas()::findOrFail($canvasId)->id` —
     * см. `resolveCanvasId()`'s докблок.
     */
    public function findCanvasId(HostDescriptor $host): ?int
    {
        if ($host->containerType === 'canvas') {
            return (int) $host->containerId;
        }

        return TgsModels::canvas()::query()
            ->where('host_type', $host->containerType)
            ->where('host_id', $host->containerId)
            ->where('group', $host->slot)
            ->where('kind', 'host')
            ->value('id');
    }

    /**
     * Инверс `resolveCanvasId()` (Этап 4, Слайс C): рёбра `HostDescriptor` для канваса,
     * читаемые НАПРЯМУЮ из собственных колонок строки `canvases` (`host_type`/`host_id`/
     * `group`), а не через `\ApiMedia\Tgs\Support\TgsModels::canvas()::hostDescriptor()`/`$canvas->host` (морф-relation). Это
     * намеренно — `\ApiMedia\Tgs\Support\TgsModels::canvas()::hostDescriptor()` перезагружает хост-модель через `MorphTo` и
     * заново вычисляет её тип (`HostDescriptor::forModelSlot()`'s own alias/snake_case
     * fallback, т.к. в приложении не зарегистрирован `Relation::morphMap()`), что не
     * гарантированно совпадает с тем, что реально записано в `host_type` ЭТОЙ строки —
     * особенно для легаси-хостов, заведённых через `resolveCanvasId()`'s ad-hoc
     * `firstOrCreate()` (вне объявленных `canvasGroups()`), у которых нет соответствующей
     * реальной Eloquent-модели, резолвящейся по `host_type` обратно. Восстановлению нужен
     * только сам триплет `(containerType, containerId, slot)` — не загруженный экземпляр
     * хоста, — так что читать сырые колонки строго надёжнее и достаточно.
     */
    private function hostDescriptorForCanvas(Canvas $canvas): HostDescriptor
    {
        return match ($canvas->kind) {
            'host' => new HostDescriptor((string) $canvas->host_type, (string) $canvas->host_id, (string) $canvas->group),
            'named', 'embedded' => HostDescriptor::forCanvas($canvas),
            default => throw new \LogicException("Unknown Canvas kind [{$canvas->kind}]"),
        };
    }

    /**
     * BFS-каскад владеемого поддерева. Вынесен из deleteBlock() ради переиспользования
     * в purgeSlot() (backlog #25). Ownership-семантика не изменена: чужой блок не трогаем,
     * всё ещё размещённый где-то (reuse) — не сносим.
     *
     * Этап 4, Слайс B: `$transactionId`/`$canvasId`/`$rootAnchor` — переданные вместе (все три
     * ненулевые ИЛИ все три null) — переводят финальную зачистку с физического удаления на
     * мягкое: `deleted_at`/`deleted_op_id` проставляются массовым UPDATE (без модельных
     * событий — медиа сознательно НЕ трогается, блок остаётся восстановимым), и ровно одна
     * строка `block_deletions` пишется на весь вызов, независимо от размера поддерева.
     * `null`-триплет (по умолчанию, как раньше вызывает `purgeSlot()`) — это единственный
     * оставшийся путь физического удаления: канвас целиком уничтожается вместе со своим
     * контентом, класть его блоки в корзину незачем (и placement-строки, документирующие
     * структуру для восстановления, там тоже стираются, а не сохраняются, — восстанавливать
     * будет некуда).
     *
     * При мягком удалении `BlockPlacement`-строки owned-поддерева (parent↔child edges внутри
     * него) НЕ удаляются — Слайс C восстанавливает структуру именно по ним. Это безопасно: раз
     * узел добрался до `$toDelete`, его СОБСТВЕННЫЙ путь наверх уже отрезан (иначе сработала бы
     * ветка reattach выше), так что оставшиеся внутренние edges недостижимы ни из какого живого
     * дерева — `CanvasTreeReader`/`BlockTreeAssembler` попросту никогда не спускаются в
     * `deleted_at`-помеченный контейнер, чтобы до них дойти.
     */
    private function cascadeOwnedSubtree(
        HostDescriptor $host,
        string $blockId,
        ?string $transactionId = null,
        ?int $canvasId = null,
        ?array $rootAnchor = null,
    ): void {
        $block = TgsModels::block()::find($blockId);
        if ($block === null) {
            return;
        }

        $ownedByHost = ($block->owner_container_type === $host->containerType
                && $block->owner_container_id === $host->containerId)
            || $block->owner_container_type === 'orphan';

        if (! $ownedByHost) {
            return;
        }

        if (TgsModels::placement()::query()->where('block_id', $blockId)->exists()) {
            // Пережил своего владельца (ещё используется где-то ещё) — переоткрепляем на
            // orphan-sentinel вместо того чтобы owner_container_* продолжал указывать на
            // хост, который блок больше не содержит (или которого может уже не быть).
            // Иначе isForeignBlock()/blockBelongsToHost() считает блок "чужим" НАВСЕГДА для
            // любого живого хоста, включая того, кто им реально пользуется, — orphan уже
            // трактуется этими проверками как "свой" для кого угодно.
            if ($block->owner_container_type !== 'orphan') {
                $block->update(TgsModels::block()::unclaimedOwner());
            }

            return;
        }

        $queue = [$blockId];
        $toDelete = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $toDelete[$current] = true;

            $childIds = TgsModels::placement()::query()
                ->where('container_type', 'block')
                ->where('container_id', $current)
                ->pluck('block_id')
                ->all();

            // Hard-delete path only (see method docblock): $current is about to be physically
            // gone, so its own children-slot edges must go with it. The soft-delete path leaves
            // these rows alone — they're the structure Слайс C restores from.
            if ($childIds !== [] && $transactionId === null) {
                TgsModels::placement()::query()->where('container_type', 'block')->where('container_id', $current)->delete();
            }

            $ownedChildren = TgsModels::block()::query()
                ->whereIn('id', $childIds)
                ->where(function ($q) use ($host): void {
                    $q->where(function ($q2) use ($host): void {
                        $q2->where('owner_container_type', $host->containerType)
                            ->where('owner_container_id', $host->containerId);
                    })->orWhere('owner_container_type', 'orphan');
                })
                ->pluck('id')
                ->all();

            foreach ($ownedChildren as $childId) {
                if (isset($toDelete[$childId])) {
                    continue;
                }

                // В мягком режиме собственный edge ребёнка под $current НЕ удаляется выше
                // (сохраняется для восстановления структуры), поэтому его нужно исключить из
                // проверки "используется ли ребёнок ещё где-то" — иначе этот же самый,
                // намеренно сохранённый placement ложно читался бы как "живое использование
                // снаружи" и ни один ребёнок не каскадировался бы дальше. В жёстком режиме
                // exclusion — no-op: этот edge к этому моменту уже физически удалён строкой
                // выше.
                $stillUsedElsewhere = TgsModels::placement()::query()
                    ->where('block_id', $childId)
                    ->where(function ($q) use ($current): void {
                        $q->where('container_type', '!=', 'block')->orWhere('container_id', '!=', $current);
                    })
                    ->exists();

                if ($stillUsedElsewhere) {
                    // Тот же случай, что и в начале метода, на уровень глубже: ребёнок
                    // пережил удаляемое поддерево (используется где-то ещё отдельно от
                    // родителя) — переоткрепляем, а не оставляем висеть на исчезающем
                    // владельце.
                    TgsModels::block()::query()->where('id', $childId)->where('owner_container_type', '!=', 'orphan')
                        ->update(TgsModels::block()::unclaimedOwner());

                    continue;
                }

                $queue[] = $childId;
            }
        }

        if ($transactionId === null) {
            TgsModels::block()::query()->whereIn('id', array_keys($toDelete))->get()->each->delete();

            return;
        }

        TgsModels::block()::query()->whereIn('id', array_keys($toDelete))->update([
            'deleted_at' => now(),
            'deleted_op_id' => $transactionId,
        ]);

        TgsModels::deletion()::create([
            'id' => (string) Str::ulid(),
            'canvas_id' => $canvasId,
            'block_id' => $blockId,
            'transaction_id' => $transactionId,
            'payload' => $rootAnchor ?? [],
            'actor_id' => auth()->id(),
            // Явно, а не полагаясь на DB-level `useCurrent()`: Postgres-сессия этого проекта
            // работает в Europe/Moscow (см. `config('app.timezone')` = UTC, `schedule_timezone`
            // = Europe/Moscow), так что `CURRENT_TIMESTAMP` пишет московское время в колонку
            // без явной таймзоны — Eloquent затем читает его обратно как если бы это уже был
            // UTC, и `created_at` "уезжает" на 3 часа в будущее (`diffForHumans()` в корзине
            // показывал «через N часов» вместо «N часов/минут назад»). `now()` здесь — то же
            // самое, что Laravel сам проставил бы для обычной timestamp-колонки.
            'created_at' => now(),
        ]);
    }

    /**
     * Полная очистка слота (backlog #25): снимает все корневые размещения слота и каскадно
     * сносит владеемое хостом поддерево. Чужие/переиспользуемые блоки остаются — удаляется
     * только ссылка на них. Используется каскадом при удалении Canvas.
     */
    public function purgeSlot(HostDescriptor $host, string $containerType, string $containerId, string $slot): void
    {
        DB::transaction(function () use ($host, $containerType, $containerId, $slot): void {
            $rootIds = TgsModels::placement()::query()
                ->where('container_type', $containerType)
                ->where('container_id', $containerId)
                ->where('slot', $slot)
                ->pluck('block_id')
                ->all();

            TgsModels::placement()::query()
                ->where('container_type', $containerType)
                ->where('container_id', $containerId)
                ->where('slot', $slot)
                ->delete();

            foreach ($rootIds as $blockId) {
                $this->cascadeOwnedSubtree($host, $blockId);
            }

            $this->signalChanged($host, []);
        });
    }
}
