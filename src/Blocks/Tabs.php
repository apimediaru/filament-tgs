<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\TabsData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Support\BlockSpec;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

class Tabs extends TgsBlock
{
    private const ITEMS = 'items';

    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-page-o';

    public static function dataClass(): ?string
    {
        return TabsData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::Tabs;
    }

    public static function blockSpec(BlockSpec ...$items): BlockSpec
    {
        return BlockSpec::for(static::class, slots: [self::ITEMS => array_values($items)]);
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
        return [self::ITEMS];
    }

    public function blockAllowedChildren(): array
    {
        return [self::ITEMS => [TabItem::blockKey()]];
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::layout();
    }
}
