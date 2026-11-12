<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\ColumnData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

class Column extends TgsBlock
{
    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-box-o';

    public static function dataClass(): ?string
    {
        return ColumnData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::Column;
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.column.label');
    }

    public function blockSlots(): array
    {
        return ['content'];
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::layout();
    }
}
