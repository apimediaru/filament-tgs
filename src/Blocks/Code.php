<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\CodeData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Illuminate\Contracts\Support\Htmlable;

class Code extends TgsBlock
{
    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-code';

    public static function dataClass(): ?string
    {
        return CodeData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::Code;
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.code.label');
    }

    public function getBlockFields(): array|Closure
    {
        return [
            CodeEditor::make('code')
                ->label(__('tgs::filament.tgs.blocks.code.fields.code.label'))
                ->language(Language::Html),
            Checkbox::make('is_pure')
                ->label(__('tgs::filament.tgs.blocks.code.fields.is_pure.label'))
                ->helperText(__('tgs::filament.tgs.blocks.code.fields.is_pure.help')),
        ];
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::content();
    }
}
