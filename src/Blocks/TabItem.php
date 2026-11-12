<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Support\Htmlable;

class TabItem extends TgsBlock
{
    protected string|\BackedEnum|Htmlable|Closure|null $icon = 'heroicon-o-window';

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::TabItem;
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.tab_item.label');
    }

    public function getBlockFields(): array|Closure
    {
        return [
            TextInput::make('title')->label(__('tgs::filament.tgs.blocks.tab_item.fields.title.label')),
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
        return __('tgs::filament.tgs.blocks.tab_item.add');
    }
}
