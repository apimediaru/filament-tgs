<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\EmbeddedData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;

class Embedded extends TgsBlock
{
    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-video-o';

    public static function dataClass(): ?string
    {
        return EmbeddedData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::Embedded;
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.embedded.label');
    }

    public function getBlockFields(): array|Closure
    {
        return [
            Group::make([
                Group::make([
                    Textarea::make('source')
                        ->label(__('tgs::filament.tgs.blocks.embedded.fields.source.label'))
                        ->helperText(__('tgs::filament.tgs.blocks.embedded.fields.source.help'))
                        ->live(onBlur: true)
                        ->required(),
                    ContentBuilderFactory::makeAspectRatioSelect('ratio')
                        ->label(__('tgs::filament.tgs.blocks.embedded.fields.ratio.label')),
                    ContentBuilderFactory::makeBooleanToggle('@toggle_preview')
                        ->label(__('tgs::filament.tgs.blocks.embedded.fields.@toggle_preview.label'))
                        ->helperText(__('tgs::filament.tgs.blocks.embedded.fields.@toggle_preview.help'))
                        ->live()
                        ->dehydrated(false),
                ])->columnSpan(fn (Get $get) => $get('@toggle_preview') ? 1 : 2),
                Group::make([
                    TextEntry::make('@preview')
                        ->label(__('tgs::filament.tgs.blocks.embedded.fields.@preview.label'))
                        ->html()
                        ->state(static function (Get $get) {
                            $source = $get('source');

                            if (empty($source)) {
                                return __('tgs::filament.tgs.blocks.embedded.fields.@preview.help');
                            }

                            return ContentBuilderFactory::renderEmbeddedPreview($source);
                        }),
                ])
                    ->visible(fn (Get $get) => ! empty($get('@toggle_preview')))
                    ->columnSpan(1),
            ])->columns(2),
        ];
    }

    public function blockPreviewFields(): array
    {
        return ['source'];
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::media();
    }
}
