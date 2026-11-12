<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Support\Htmlable;

class AccordionItem extends TgsBlock
{
    protected string|\BackedEnum|Htmlable|Closure|null $icon = 'heroicon-o-bars-3-bottom-left';

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::AccordionItem;
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.accordion_item.label');
    }

    public function getBlockFields(): array|Closure
    {
        return [
            TextInput::make('title')->label(__('tgs::filament.tgs.blocks.accordion_item.fields.title.label')),
            Checkbox::make('is_open')->label(__('tgs::filament.tgs.blocks.accordion_item.fields.is_open.label')),
        ];
    }

    public function blockSlots(): array
    {
        return ['content'];
    }

    public static function toText(array $data): ?string
    {
        return trim($data['title'] ?? '') ?: null;
    }

    public function blockPreviewFields(): array
    {
        return ['title'];
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::layout();
    }

    public function isChildOnlyBlock(): bool
    {
        return true;
    }

    public function blockAddLabel(): ?string
    {
        return __('tgs::filament.tgs.blocks.accordion_item.add');
    }
}
