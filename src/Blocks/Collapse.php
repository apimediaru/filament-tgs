<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\CollapseData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\RichEditor;
use Illuminate\Contracts\Support\Htmlable;

class Collapse extends TgsBlock
{
    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-layers';

    public static function dataClass(): ?string
    {
        return CollapseData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::Collapse;
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.collapse.label');
    }

    public function getBlockFields(): array|Closure
    {
        return [
            RichEditor::make('title')
                ->label(__('tgs::filament.tgs.blocks.collapse.fields.title.label'))
                ->required()
                ->disableToolbarButtons([
                    'attachFiles',
                    'blockquote',
                    'bulletList',
                    'codeBlock',
                    'h2',
                    'h3',
                    'orderedList',
                ]),
            Checkbox::make('is_open')
                ->label(__('tgs::filament.tgs.blocks.collapse.fields.is_open.label'))
                ->helperText(__('tgs::filament.tgs.blocks.collapse.fields.is_open.help'))
                ->default(fn () => false),
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
}
