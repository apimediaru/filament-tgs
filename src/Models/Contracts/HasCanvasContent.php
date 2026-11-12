<?php

namespace ApiMedia\Tgs\Models\Contracts;

/**
 * Read-only проекция ТГС-канваса в структурированный блочный контент и plain-text
 * (реализация — ApiMedia\Tgs\Models\Concerns\HasCanvasContent). Замена read-части
 * HasContentBuilder (см. ADR-013) для хостов на новом канвасе: без колонки и без
 * getContentBuilderKeyName()/contentBuilderColumns() — источник правды это
 * blocks/block_placements через HasCanvases, параметр — имя группы, не колонки.
 */
interface HasCanvasContent
{
    /** @return array<int, mixed>|null */
    public function getContent(?string $group = null): ?array;

    /** @return array<int, mixed>|null */
    public function getContentAttribute(): ?array;

    public function getTextContent(?string $group = null): ?string;

    public function getTextContentAttribute(): ?string;
}
