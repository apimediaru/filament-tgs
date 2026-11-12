<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Services;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Support\BlockTreeHydrator;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsModels;
use ExileOfAranei\ListOrdering\Support\GroupKey;
use RuntimeException;

use function array_unique;
use function array_values;

/**
 * Read-side tree assembler: BFS-walks block_placements
 * starting from the host's root slot, batch-loads blocks + next-level edges
 * per level, and hands the collected {blocks, edges} to BlockTreeHydrator to
 * rebuild the nested tree shape consumed by BlockCollectionData::fromStored.
 *
 * Note: no eager-loading strategy exists yet for batch reads across multiple
 * hosts — each call issues its own per-level queries (root edges, then one
 * \ApiMedia\Tgs\Support\TgsModels::block()::whereIn + one \ApiMedia\Tgs\Support\TgsModels::placement()::whereIn per BFS level). List endpoints
 * that call getContent()/fromPage()/assembleItems() on a collection of hosts
 * (e.g. a News/Article index) have an N+1-shaped query pattern here and should
 * be audited before this pipeline is used on hot list paths. Deferred: a real
 * fix means eager-loading a polymorphic placements tree across many roots at
 * once, which is out of scope for this foundation phase.
 */
class BlockTreeAssembler
{
    /**
     * @return array<int, array{type:string,data:array}>
     */
    public function assemble(HostDescriptor $host): array
    {
        $rootEdges = TgsModels::placement()::query()
            ->ordered(GroupKey::of([
                'container_type' => $host->containerType,
                'container_id' => $host->containerId,
                'slot' => $host->slot,
            ]))
            ->get(['rank', 'block_id']);

        if ($rootEdges->isEmpty()) {
            return [];
        }

        $edges = [];
        foreach ($rootEdges as $e) {
            $edges[] = ['parent_id' => null, 'slot' => $host->slot, 'position' => $e->rank, 'child_id' => $e->block_id];
        }

        $blocks = [];
        $visited = [];
        $frontier = $rootEdges->pluck('block_id')->unique()->all();

        while ($frontier !== []) {
            $level = [];
            foreach ($frontier as $id) {
                if (isset($visited[$id])) {
                    report(new RuntimeException("BlockTreeAssembler: cycle detected at block {$id} on {$host->containerType}#{$host->containerId}/{$host->slot}"));

                    continue;
                }
                $visited[$id] = true;
                $level[] = $id;
            }

            if ($level === []) {
                break;
            }

            // Этап 4 (корзина): мягко удалённые блоки не участвуют в публичном чтении — ни
            // сами (whereNull ниже), ни как контейнеры для дальнейшего обхода ($aliveIds вместо
            // $level в children-edges-запросе), иначе их потомки утекли бы в вывод в обход
            // невидимого удалённого родителя.
            $aliveBlocks = TgsModels::block()::query()->whereIn('id', $level)->whereNull('deleted_at')->get();
            foreach ($aliveBlocks as $block) {
                $blocks[] = ['id' => $block->id, 'type' => $block->type, 'data' => $block->data ?? []];
            }
            $aliveIds = $aliveBlocks->pluck('id')->all();

            $childEdges = TgsModels::placement()::query()
                ->where('container_type', 'block')
                ->whereIn('container_id', $aliveIds)
                ->ordered()
                ->get(['container_id', 'slot', 'rank', 'block_id']);

            $next = [];
            foreach ($childEdges as $e) {
                $childId = $e->block_id;

                if (isset($visited[$childId])) {
                    // Child already visited elsewhere in the tree: including this edge
                    // would make the hydrator re-expand an already-built node, looping
                    // forever on a cycle. Drop the edge and report instead.
                    report(new RuntimeException("BlockTreeAssembler: cycle detected — block {$childId} re-referenced from block {$e->container_id} on {$host->containerType}#{$host->containerId}/{$host->slot}"));

                    continue;
                }

                $edges[] = ['parent_id' => $e->container_id, 'slot' => $e->slot, 'position' => $e->rank, 'child_id' => $childId];
                $next[] = $childId;
            }

            $frontier = array_values(array_unique($next));
        }

        return BlockTreeHydrator::hydrate($blocks, $edges, rootParentId: null, rootSlot: $host->slot);
    }

    /**
     * Резолвит произвольный блок как корень поддерева (не хост) — для вставки
     * существующего блока в чужое дерево (reuse). Сознательно не переиспользует
     * приватные детали assemble() — тот метод уже покрыт тестами хостового пути,
     * трогать его ради этого узкого случая рискованнее, чем немного продублировать
     * BFS. Тот же cycle-guard (report(), не exception), что и в assemble().
     *
     * @return array{type:string,data:array}|null
     */
    public function assembleBlock(string $blockId): ?array
    {
        // Этап 4 (корзина): мягко удалённый блок трактуется как отсутствующий для этого
        // публичного пути ровно как и в assemble() выше.
        $root = TgsModels::block()::query()->whereNull('deleted_at')->find($blockId);

        if ($root === null) {
            return null;
        }

        $blocks = [['id' => $root->id, 'type' => $root->type, 'data' => $root->data ?? []]];
        $edges = [
            ['parent_id' => null, 'slot' => BlockTreeHydrator::ROOT_SLOT, 'position' => 0, 'child_id' => $blockId],
        ];
        $visited = [$blockId => true];
        $frontier = [$blockId];

        while ($frontier !== []) {
            $childEdges = TgsModels::placement()::query()
                ->where('container_type', 'block')
                ->whereIn('container_id', $frontier)
                ->ordered()
                ->get(['container_id', 'slot', 'rank', 'block_id']);

            $next = [];
            foreach ($childEdges as $e) {
                $childId = $e->block_id;

                if (isset($visited[$childId])) {
                    report(new RuntimeException("BlockTreeAssembler::assembleBlock: cycle detected — block {$childId} re-referenced from block {$e->container_id}, root {$blockId}"));

                    continue;
                }

                $visited[$childId] = true;
                $edges[] = ['parent_id' => $e->container_id, 'slot' => $e->slot, 'position' => $e->rank, 'child_id' => $childId];
                $next[] = $childId;
            }

            $aliveNext = [];
            if ($next !== []) {
                $aliveBlocks = TgsModels::block()::query()->whereIn('id', $next)->whereNull('deleted_at')->get();
                foreach ($aliveBlocks as $block) {
                    $blocks[] = ['id' => $block->id, 'type' => $block->type, 'data' => $block->data ?? []];
                }
                $aliveNext = $aliveBlocks->pluck('id')->all();
            }

            $frontier = array_values(array_unique($aliveNext));
        }

        $hydrated = BlockTreeHydrator::hydrate($blocks, $edges, rootParentId: null, rootSlot: BlockTreeHydrator::ROOT_SLOT);

        return $hydrated[0] ?? null;
    }
}
