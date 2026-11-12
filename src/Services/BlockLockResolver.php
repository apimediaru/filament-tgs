<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Services;

use ApiMedia\Tgs\Enums\BlockLockMode;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Support\TgsModels;

/**
 * Read-side check for §12 (block locking): is a block locked, either directly or by inheriting a
 * `cascade` lock from an ancestor. Walks block_placements from the block up to its parents (the
 * mirror direction of BlockTreeAssembler, which walks parent -> children).
 *
 * A reused block can sit in several containers at once, so the walk is breadth-first over EVERY
 * parent block, not one arbitrary placement (hotfix slice 1, bug 3): the block is locked if any
 * path up carries a cascade lock. It is the same block everywhere — an edit through an unlocked
 * path would change it inside the locked one too; an editor who needs their own version detaches
 * a copy. Only `block` containers are walked: a root placement on a host has no lock to inherit.
 */
class BlockLockResolver
{
    public function isEffectivelyLocked(string $blockId): bool
    {
        $block = TgsModels::block()::query()->find($blockId);

        if ($block === null) {
            return false;
        }

        if ($block->is_locked) {
            return true;
        }

        $visited = [$blockId => true];
        $frontier = [$blockId];

        // Terminates: every iteration either returns or moves to strictly unvisited parents.
        while (true) {
            $parentIds = TgsModels::placement()::query()
                ->where('container_type', 'block')
                ->whereIn('block_id', $frontier)
                ->distinct()
                ->pluck('container_id')
                ->reject(fn (string $id): bool => isset($visited[$id]))
                ->values()
                ->all();

            if ($parentIds === []) {
                return false;
            }

            $cascadeLockedParent = TgsModels::block()::query()
                ->whereIn('id', $parentIds)
                ->where('is_locked', true)
                ->where('lock_mode', BlockLockMode::Cascade)
                ->exists();

            if ($cascadeLockedParent) {
                return true;
            }

            foreach ($parentIds as $parentId) {
                $visited[$parentId] = true;
            }

            $frontier = $parentIds;
        }
    }
}
