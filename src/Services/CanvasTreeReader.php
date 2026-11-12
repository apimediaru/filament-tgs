<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Services;

use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsModels;
use ExileOfAranei\ListOrdering\Support\GroupKey;
use Illuminate\Support\Str;

/**
 * Лёгкое структурное дерево группы для канваса (ADR-019): id/type/preview-скаляры/слоты→дети.
 * НЕ полный fromStored (тот для фронта) — канвасу нужна структура, не рендер-DTO.
 *
 * Cycle-guard — path-based (per-branch visited-set), а не глобальный, как в BlockTreeAssembler:
 * ловит настоящие циклы (блок оказывается собственным предком по одной ветке), но намеренно
 * допускает один и тот же блок в нескольких независимых слотах (легитимный reuse, §10) —
 * глобальный visited-set BlockTreeAssembler в этом случае некорректно дропнул бы второе
 * вхождение.
 */
class CanvasTreeReader
{
    /** @return list<array{id:string,placementId:int,type:string,preview:array<string,mixed>,previewLine:?string,childCount:int,hasData:bool,name:?string,slots:array<string,list<mixed>>}> список корневых узлов */
    public function read(HostDescriptor $root): array
    {
        return $this->children($root->containerType, $root->containerId, $root->slot, []);
    }

    /**
     * @param  array<string, true>  $visited
     * @return list<array{id:string,placementId:int,type:string,preview:array<string,mixed>,previewLine:?string,childCount:int,hasData:bool,name:?string,slots:array<string,list<mixed>>}>
     */
    private function children(string $containerType, string $containerId, string $slot, array $visited): array
    {
        $edges = TgsModels::placement()::query()
            ->ordered(GroupKey::of([
                'container_type' => $containerType,
                'container_id' => $containerId,
                'slot' => $slot,
            ]))
            ->get(['id', 'block_id']);

        // Batch the block lookup across the whole slot instead of one \ApiMedia\Tgs\Support\TgsModels::block()::find() per edge —
        // the previous per-edge query gave an N+1 (31 queries for 30 flat blocks). Duplicate
        // block_id values (legit reuse, e.g. the same block placed twice in one slot, §10) are
        // deduped only for the whereIn() itself; each edge still produces its own node below.
        // Этап 4 (корзина): мягко удалённые блоки не показываются в обычном дереве канваса
        // (у них появится отдельное представление в корзине, Слайс D) — та же трактовка
        // "как будто отсутствует", что и для физически несуществующего id.
        $blocksById = TgsModels::block()::query()
            ->whereIn('id', $edges->pluck('block_id')->unique())
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        $nodes = [];
        foreach ($edges as $edge) {
            $blockId = $edge->block_id;
            if (isset($visited[$blockId])) {
                continue;
            }
            $block = $blocksById->get($blockId);
            if ($block === null) {
                continue;
            }

            $nodes[] = $this->node($block, $edge->id, [...$visited, $blockId => true]);
        }

        return $nodes;
    }

    /**
     * @param  array<string, true>  $visited
     * @return array{id:string,placementId:int,type:string,preview:array<string,mixed>,previewLine:?string,childCount:int,hasData:bool,name:?string,slots:array<string,list<mixed>>}
     */
    private function node(Block $block, int $placementId, array $visited): array
    {
        $data = $block->data ?? [];
        $preview = [];
        $slots = [];
        $previewLine = null;

        $class = ContentBuilderFactory::blockClassFor($block->type);
        if ($class !== null) {
            $instance = $class::make();
            foreach ($instance->blockPreviewFields() as $field) {
                $preview[$field] = $this->plainPreview($data[$field] ?? null);
            }
            foreach ($instance->blockSlots() as $slotName) {
                $slots[$slotName] = $this->children('block', $block->id, $slotName, $visited);
            }
            $previewLine = $instance->blockPreview($data, $block);
        }

        $childCount = array_sum(array_map('count', $slots));

        return [
            'id' => $block->id,
            'placementId' => $placementId,
            'type' => $block->type,
            'preview' => $preview,
            'previewLine' => $previewLine,
            'childCount' => $childCount,
            'hasData' => $this->hasMeaningfulData($data),
            'name' => $block->name,
            'slots' => $slots,
        ];
    }

    /**
     * «Заполненность» листового блока (без слотов) для fallback-статуса в узле канваса, когда
     * у типа блока нет ни `blockPreviewFields()`, ни `blockPreview()` (напр. Code): bool/null
     * не считаются заполнением — иначе чекбокс в дефолтном `false` ложно пометил бы
     * свежесозданный пустой блок «Заполнено».
     *
     * @param  array<string, mixed>  $data
     */
    private function hasMeaningfulData(array $data): bool
    {
        foreach ($data as $value) {
            if ($value === null || is_bool($value)) {
                continue;
            }
            if (is_string($value)) {
                if (trim(strip_tags($value)) !== '') {
                    return true;
                }

                continue;
            }
            if (is_array($value)) {
                if ($value !== []) {
                    return true;
                }

                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Plain-text превью поля для дерева канваса (plan 2b polish): снимает HTML-разметку и
     * схлопывает пробелы, чтобы менеджер видел читаемый текст, а не сырой `<p>…</p>`.
     * Не-строки возвращаются как есть. Cap в 200 символов — CSS в блейде дообрезает по ширине.
     */
    private function plainPreview(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));

        return Str::limit($text, 200);
    }
}
