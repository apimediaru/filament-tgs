<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Models\Concerns;

use ApiMedia\Tgs\Data\BlockCollectionData;
use ApiMedia\Tgs\Services\BlockTreeAssembler;
use ApiMedia\Tgs\Support\BlockTreeText;

/**
 * Чтение ТГС-контента поверх групп (спек retire-old-builder, §4.2) — замена read-части
 * HasContentBuilderTrait, не зависящая от JSON-колонки.
 *
 * В легаси-трейте колонка работала гейтом (`if (!is_array($raw)) return null`), хотя
 * источником правды уже были blocks/block_placements. Здесь гейт и cutover-fallback
 * отсутствуют — падать не на что, дерево всегда собирается ассемблером.
 *
 * Требует HasCanvases.
 */
trait HasCanvasContent
{
    /** @return array<int, mixed>|null */
    public function getContent(?string $group = null): ?array
    {
        $tree = $this->assembleGroupTree($group);

        return $tree === [] ? null : BlockCollectionData::fromStored($tree, $this);
    }

    /** @return array<int, mixed>|null */
    public function getContentAttribute(): ?array
    {
        return $this->getContent();
    }

    public function getTextContent(?string $group = null): ?string
    {
        $tree = $this->assembleGroupTree($group);

        return $tree === [] ? null : BlockTreeText::extractText($tree);
    }

    public function getTextContentAttribute(): ?string
    {
        return $this->getTextContent();
    }

    /** @return array<int, mixed> */
    protected function assembleGroupTree(?string $group): array
    {
        $key = $group ?? static::canvasGroups()[0];

        return app(BlockTreeAssembler::class)->assemble($this->canvasDescriptor($key));
    }
}
