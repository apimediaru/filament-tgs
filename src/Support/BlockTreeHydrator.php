<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Support;

use RuntimeException;

use function array_keys;
use function explode;
use function str_contains;
use function usort;

class BlockTreeHydrator
{
    public const ROOT_SLOT = '__root__';

    /**
     * Positions may be dense fixture indices or fractional ranks from placements.
     *
     * @param  list<array{id:string,type:string,data:array}>  $blocks
     * @param  list<array{parent_id:?string,slot:string,position:int|string,child_id:string}>  $edges
     * @return array<int, array{type:string,data:array}>
     */
    public static function hydrate(
        array $blocks,
        array $edges,
        ?string $rootParentId = null,
        string $rootSlot = BlockTreeHydrator::ROOT_SLOT,
    ): array {
        $byId = [];
        foreach ($blocks as $b) {
            $byId[$b['id']] = $b;
        }

        $childrenIndex = [];
        foreach ($edges as $e) {
            $childrenIndex[$e['parent_id'] ?? '__null__'][$e['slot']][] = $e;
        }
        foreach ($childrenIndex as &$slots) {
            foreach ($slots as &$list) {
                usort($list, static fn ($a, $b) => $a['position'] <=> $b['position']);
            }
        }
        unset($slots, $list);

        return self::buildList($rootParentId, $rootSlot, $byId, $childrenIndex);
    }

    /**
     * @param  array<string, true>  $ancestors
     * @param  array<string, array>  $byId
     * @param  array<string, array<string, list<array>>>  $childrenIndex
     * @return array<int, array{type:string,data:array}>
     */
    private static function buildList(?string $parentId, string $slot, array $byId, array $childrenIndex, array $ancestors = []): array
    {
        $key = $parentId ?? '__null__';
        $edges = $childrenIndex[$key][$slot] ?? [];

        $out = [];
        foreach ($edges as $edge) {
            if (! isset($byId[$edge['child_id']])) {
                continue; // висячая ссылка — пропускаем (assembler уже отрепортил)
            }
            if (isset($ancestors[$edge['child_id']])) {
                report(new RuntimeException("BlockTreeHydrator: cycle detected at block {$edge['child_id']}"));

                continue;
            }
            $out[] = self::buildBlock($edge['child_id'], $byId, $childrenIndex, $ancestors);
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $ancestors
     * @param  array<string, array>  $byId
     * @param  array<string, array<string, list<array>>>  $childrenIndex
     * @return array{type:string,data:array}
     */
    private static function buildBlock(string $id, array $byId, array $childrenIndex, array $ancestors = []): array
    {
        $ancestors[$id] = true;
        $block = $byId[$id];
        $data = $block['data'];

        foreach (array_keys($childrenIndex[$id] ?? []) as $slot) {
            $children = self::buildList($id, $slot, $byId, $childrenIndex, $ancestors);

            if (str_contains($slot, '.')) {
                [$field, $index, $subField] = explode('.', $slot, 3);
                $data[$field][(int) $index][$subField] = $children;
            } else {
                $data[$slot] = $children;
            }
        }

        $data['id'] = $id;

        return ['type' => $block['type'], 'data' => $data];
    }
}
