<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Support\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Tests\Feature\Tgs\TgsCanvasReadonlyBladeGuardTest;
use ApiMedia\Tgs\Tests\Feature\Tgs\TgsCanvasReadonlyGuardsTest;
use ReflectionClass;
use ReflectionMethod;

use function in_array;

/**
 * Single source of truth for "which public methods of {@see TgsCanvas} are mutating entry points".
 *
 * The read-only correctness of the canvas is defined by exactly one set — its mutating methods.
 * Both guards that protect it derive from THIS enumeration, so they can never drift apart:
 *  - server side (backlog #34, {@see TgsCanvasReadonlyGuardsTest}): every name
 *    here must call guardWritable() in its body;
 *  - UI side (backlog #36, {@see TgsCanvasReadonlyBladeGuardTest}): no name
 *    here may leak its blade affordance marker into a read-only render.
 *
 * A newly added mutating method is therefore guarded-by-default on BOTH axes with zero manual list
 * upkeep — the only maintenance point is NON_MUTATING below, when a genuinely non-mutating public
 * method is added (each entry carries its reason).
 */
final class TgsCanvasMutators
{
    /**
     * Public methods DECLARED IN TgsCanvas's own file that are genuinely NOT mutating entry points.
     * Everything else declared there is treated as a mutator.
     *
     * @var list<string>
     */
    public const NON_MUTATING = [
        // Livewire/framework lifecycle hooks, no canvas mutation.
        'mount',
        'hydrate',
        'render',
        // ADR-020 draft cleanup on modal cancel: deletes only an UNCOMMITTED draft the same request
        // created (never a committed block) — not a mutation a read-only viewer could trigger.
        'unmountAction',

        // Picker drawer UI state only, no DB write.
        'openPicker',
        'closePicker',
        // The action definition itself: mounting/rendering it opens a UI panel (like openPicker) —
        // its mutating sub-actions (chooseBlock/insertFromLibrary/insertFromCollection) each guard.
        'insertBlockAction',

        // Livewire #[Computed] getters: memoized reads, never write.
        'getTreeProperty',
        'getPickerBlocksProperty',
        'getPickerIsFilteredProperty',
        'getLibraryBlocksProperty',
        'getCollectionsProperty',

        // The 14 non-mutating members named in the read-only design spec.
        'slotLabel',
        'isLibraryHost',
        'isCollectionHost',
        'slotSingleChild',
        'slotAddLabel',
        'isForeignBlock',
        'isReferencePlacement',
        'referenceSourceLabel',
        'libraryUsageCount',
        'rootContainer',
        'availableBlocksFor',
        'canManageLocks',
        'lockState',
        'lockHint',
        'blockMeta',

        // Слайс E (DnD между слотами): plain HostDescriptor getter, made public so the blade can
        // stamp the root sortable container's data-container-*/data-slot attributes. No DB write.
        'host',

        // Reads a collection's top-level blocks for the "insert from collection" picker tab; the
        // actual insertion (insertFromCollection/insertFromCollectionAction) is guarded separately.
        'collectionBlocks',

        // Этап 3, Слайс B ("где используется"): pure reads over CanvasPersistence::
        // resolveUsagePlaces() (+ HostLabelResolver for usagePlaces()'s labels/urls) — no DB write.
        'usagePlacesCount',
        'usagePlaces',
        // Node header priority/compaction plan follow-up: pure read (BlockPlacement::count())
        // backing the node badge's chip gate/number — no DB write.
        'blockPlacementsCount',
        // Этап 4, Слайс E: pure read (CanvasPersistence::countLiveDescendants()) backing the
        // delete confirmation's nested-count wording — no DB write.
        'nestedLiveDescendantsCount',

        // Этап 4, Слайс D (корзина): pure type-metadata resolver (icon/label for a block class) —
        // made public static so TgsTrashDrawer, a SEPARATE Livewire component, can reuse the exact
        // same icon/label resolution as the tree's own blockMeta() without duplicating it. No DB
        // write, no instance state.
        'describeBlock',
        // Осиротевшие блоки (2026-07-25): pure schema builder (tabs/fields for the edit modal),
        // no instance state, no DB write — made public static so OrphanedBlocksTable, a SEPARATE
        // Livewire component, can build the exact same edit-modal schema without duplicating it.
        'blockEditSchema',
        // Livewire #[Computed] getter: memoized read (block_deletions count for the header badge).
        'getTrashCountProperty',
        // Cross-component refresh bridge: TgsTrashDrawer (a separate Livewire component) dispatches
        // 'tgs-canvas-changed' after restore() mutates state on ITS side — this listener's body is
        // intentionally empty, its only job is to make Livewire re-render THIS component so
        // getTreeProperty()/getTrashCountProperty() recompute. See its own docblock.
        'handleExternalCanvasChange',
    ];

    /**
     * Mutating public method names: public methods declared in TgsCanvas's own file, minus
     * NON_MUTATING. `getFileName()` (unlike `getDeclaringClass()`, which trait-flattening reports
     * as TgsCanvas) still points at a trait method's own source, so this excludes framework/trait
     * mixins (mountAction, getRules, __get, ...).
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $class = new ReflectionClass(TgsCanvas::class);
        $classFile = $class->getFileName();

        $names = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getFileName() !== $classFile) {
                continue;
            }

            if (in_array($method->getName(), self::NON_MUTATING, true)) {
                continue;
            }

            $names[] = $method->getName();
        }

        return $names;
    }
}
