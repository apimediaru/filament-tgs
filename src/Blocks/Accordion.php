<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\AccordionData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Filament\Forms\Components\Checkbox;
use Illuminate\Contracts\Support\Htmlable;

class Accordion extends TgsBlock
{
    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-topbar-o';

    public static function dataClass(): ?string
    {
        return AccordionData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::Accordion;
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.accordion.label');
    }

    public function getBlockFields(): array|Closure
    {
        return [
            Checkbox::make('is_accordion')
                ->label(__('tgs::filament.tgs.blocks.accordion.fields.is_accordion.label'))
                ->helperText(__('tgs::filament.tgs.blocks.accordion.fields.is_accordion.help')),
        ];
    }

    public function blockSlots(): array
    {
        return ['items'];
    }

    public function blockAllowedChildren(): array
    {
        return ['items' => ['accordion_item']];
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::layout();
    }
}
