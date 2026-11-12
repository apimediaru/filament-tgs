<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\PopupData;
use ApiMedia\Tgs\Enums\ButtonTheme;
use ApiMedia\Tgs\Enums\ButtonVariant;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Enums\PopupSize;
use ApiMedia\Tgs\Enums\Position;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;

class Popup extends TgsBlock
{
    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-slide-o';

    public static function dataClass(): ?string
    {
        return PopupData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::Popup;
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.popup.label');
    }

    public function getBlockFields(): array|Closure
    {
        return [
            Tabs::make()
                ->tabs([
                    Tab::make('main')
                        ->label(__('tgs::filament.tgs.blocks.popup.tabs.main.label'))
                        ->schema([
                            TextInput::make('button_label')
                                ->label(__('tgs::filament.tgs.blocks.popup.fields.button_label.label'))
                                ->helperText(__('tgs::filament.tgs.blocks.popup.fields.button_label.help')),
                        ]),
                    Tab::make('appearance')
                        ->label(__('tgs::filament.tgs.blocks.popup.tabs.appearance.label'))
                        ->schema([
                            Select::make('size')
                                ->label(__('tgs::filament.tgs.blocks.popup.fields.size.label'))
                                ->options(PopupSize::class),
                        ])
                        ->schema([
                            Select::make('size')
                                ->label(__('tgs::filament.tgs.blocks.popup.fields.size.label'))
                                ->options(PopupSize::class),
                            Select::make('button_theme')
                                ->label(__('tgs::filament.tgs.blocks.popup.fields.button_theme.label'))
                                ->options(ButtonTheme::class),
                            Select::make('button_variant')
                                ->label(__('tgs::filament.tgs.blocks.popup.fields.button_variant.label'))
                                ->options(ButtonVariant::class),
                            Select::make('button_alignment')
                                ->label(__('tgs::filament.tgs.blocks.popup.fields.button_alignment.label'))
                                ->options(Position::class),
                            TextInput::make('button_class')
                                ->label(__('tgs::filament.tgs.blocks.popup.fields.button_class.label')),
                        ])
                        ->columns(2),
                ]),
        ];
    }

    public function blockSlots(): array
    {
        return ['content'];
    }

    public static function toText(array $data): ?string
    {
        return trim($data['button_label'] ?? '') ?: null;
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::layout();
    }
}
