<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\DoubleColumnData;
use ApiMedia\Tgs\Enums\ColumnWidth;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Enums\Justification;
use ApiMedia\Tgs\Enums\TgsBlockLayout;
use ApiMedia\Tgs\Support\BlockSpec;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;

class DoubleColumn extends TgsBlock
{
    private const LEFT = 'left';

    private const RIGHT = 'right';

    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-column-o';

    public static function dataClass(): ?string
    {
        return DoubleColumnData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::DoubleColumn;
    }

    /**
     * @param  BlockSpec|list<BlockSpec>  $left
     * @param  BlockSpec|list<BlockSpec>  $right
     */
    public static function seed(BlockSpec|array $left = [], BlockSpec|array $right = []): BlockSpec
    {
        return BlockSpec::of(static::blockKey(), slots: [self::LEFT => $left, self::RIGHT => $right]);
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.double_column.label');
    }

    public function getBlockFields(): array|Closure
    {
        return function () {
            return [
                Tabs::make('content')
                    ->contained(true)
                    ->tabs([
                        Tab::make(__('tgs::filament.tgs.blocks.double_column.fields.left.label'))
                            ->schema($this->getColumnFields(self::LEFT)),
                        Tab::make(__('tgs::filament.tgs.blocks.double_column.fields.right.label'))
                            ->schema($this->getColumnFields(self::RIGHT)),
                    ]),
                Section::make([
                    Select::make('justification')
                        ->options(Justification::class)
                        ->native(false)
                        ->label(__('tgs::filament.tgs.blocks.double_column.fields.justification.label')),
                    Checkbox::make('is_aligned_vertically')
                        ->label(__('tgs::filament.tgs.blocks.double_column.fields.is_aligned_vertically.label')),
                    Checkbox::make('is_swapped')
                        ->label(__('tgs::filament.tgs.blocks.double_column.fields.is_swapped.label'))
                        ->helperText(__('tgs::filament.tgs.blocks.double_column.fields.is_swapped.help')),
                ])
                    ->compact()
                    ->collapsible()
                    ->heading(__('tgs::filament.tgs.blocks.double_column.sections.settings.label')),
            ];
        };
    }

    public function blockSlots(): array
    {
        return [self::LEFT, self::RIGHT];
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::layout();
    }

    public function blockLayout(): TgsBlockLayout
    {
        return TgsBlockLayout::Row;
    }

    protected function getColumnFields(string $key): array
    {
        return [
            Select::make("{$key}_column_width")
                ->options(ColumnWidth::class)
                ->native(false)
                ->label(__("tgs::filament.tgs.blocks.double_column.fields.{$key}_column_width.label")),
        ];
    }
}
