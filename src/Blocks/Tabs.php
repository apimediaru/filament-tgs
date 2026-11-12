<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\TabsData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

class Tabs extends TgsBlock
{
    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-page-o';

    public static function dataClass(): ?string
    {
        return TabsData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::Tabs;
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.tabs.label');
    }

    public function getBlockFields(): array|Closure
    {
        return [];
    }

    public function blockSlots(): array
    {
        return ['items'];
    }

    public function blockAllowedChildren(): array
    {
        return ['items' => ['tab_item']];
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::layout();
    }
}
