<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\GalleryData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Support\BlockSpec;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use ApiMedia\Tgs\Support\TgsModels;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

class Gallery extends TgsBlock
{
    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-carousel-o';

    public static function dataClass(): ?string
    {
        return GalleryData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::Gallery;
    }

    public static function seed(string ...$files): BlockSpec
    {
        return BlockSpec::of(static::blockKey(), files: array_values($files));
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.gallery.label');
    }

    public function getBlockFields(): array|Closure
    {
        return [
            static::mediaUuidHiddenField(),
            SpatieMediaLibraryFileUpload::make('image')
                ->when(($disk = static::contentMediaDisk()) !== null, fn (SpatieMediaLibraryFileUpload $field) => $field->disk($disk))
                // TODO: check if possible adding custom properties via editor
                ->model(fn (Get $get) => static::resolveMediaHostBlock($get('id')))
                ->imageEditor()
                ->label(__('tgs::filament.tgs.blocks.gallery.fields.images.label'))
                ->required()
                ->panelLayout('grid')
                ->multiple()
                ->downloadable()
                ->openable()
                ->reorderable()
                ->appendFiles()
                ->customProperties(fn (Get $get): array => ['uuid' => $get('uuid')])
                ->collection(fn () => TgsModels::block()::getContentMediaCollectionName())
                ->filterMediaUsing(
                    fn (Collection $media, Get $get): Collection => $media->where(
                        'custom_properties.uuid',
                        $get('uuid')
                    ),
                ),
            Section::make('configuration')
                ->heading(__('tgs::filament.tgs.blocks.gallery.sections.configuration.label'))
                ->columns(2)
                ->collapsible()
                ->schema([
                    ContentBuilderFactory::makeAspectRatioSelect('aspect_ratio')
                        ->label(__('tgs::filament.tgs.blocks.gallery.fields.aspect_ratio.label'))
                        ->helperText(__('tgs::filament.tgs.blocks.gallery.fields.aspect_ratio.help'))
                        ->native(false),
                    Select::make('slide_count')
                        ->label(__('tgs::filament.tgs.blocks.gallery.fields.slide_count.label'))
                        ->native(false)
                        ->options([
                            '1' => __('tgs::filament.tgs.blocks.gallery.fields.slide_count.options.one'),
                            '2' => __('tgs::filament.tgs.blocks.gallery.fields.slide_count.options.two'),
                            '3' => __('tgs::filament.tgs.blocks.gallery.fields.slide_count.options.three'),
                            '4' => __('tgs::filament.tgs.blocks.gallery.fields.slide_count.options.four'),
                            'auto' => __('tgs::filament.tgs.blocks.gallery.fields.slide_count.options.auto'),
                        ]),
                    Group::make([
                        Checkbox::make('is_lightbox')
                            ->label(__('tgs::filament.tgs.blocks.gallery.fields.is_lightbox.label'))
                            ->helperText(__('tgs::filament.tgs.blocks.gallery.fields.is_lightbox.help'))
                            ->default(true),
                        Checkbox::make('is_original')
                            ->label(__('tgs::filament.tgs.blocks.gallery.fields.is_original.label'))
                            ->helperText(__('tgs::filament.tgs.blocks.gallery.fields.is_original.help'))
                            ->default(false),
                        Checkbox::make('is_cropped')
                            ->label(__('tgs::filament.tgs.blocks.gallery.fields.is_cropped.label'))
                            ->helperText(__('tgs::filament.tgs.blocks.gallery.fields.is_cropped.help'))
                            ->default(false),
                        Checkbox::make('is_rounded')
                            ->label(__('tgs::filament.tgs.blocks.gallery.fields.is_rounded.label'))
                            ->helperText(__('tgs::filament.tgs.blocks.gallery.fields.is_rounded.help'))
                            ->default(false),
                        Checkbox::make('is_centered')
                            ->label(__('tgs::filament.tgs.blocks.gallery.fields.is_centered.label'))
                            ->helperText(__('tgs::filament.tgs.blocks.gallery.fields.is_centered.help'))
                            ->default(false),
                        Checkbox::make('is_looped')
                            ->label(__('tgs::filament.tgs.blocks.gallery.fields.is_looped.label'))
                            ->helperText(__('tgs::filament.tgs.blocks.gallery.fields.is_looped.help'))
                            ->default(false),
                        Checkbox::make('is_rewound')
                            ->label(__('tgs::filament.tgs.blocks.gallery.fields.is_rewound.label'))
                            ->helperText(__('tgs::filament.tgs.blocks.gallery.fields.is_rewound.help'))
                            ->default(false),
                    ])
                        ->columnSpanFull()
                        ->columns(2),
                ]),
        ];
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::media();
    }

    public function blockPreview(array $data, Block $block): ?string
    {
        $uuid = is_string($data['uuid'] ?? null) ? $data['uuid'] : null;
        $count = $block->contentMediaWithUuid($uuid)->count();

        return $count > 0
            ? __('tgs::filament.tgs.blocks.gallery.preview', ['count' => $count])
            : __('tgs::filament.tgs.blocks.gallery.preview_empty');
    }
}
