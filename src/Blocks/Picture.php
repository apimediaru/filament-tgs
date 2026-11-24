<?php

namespace ApiMedia\Tgs\Blocks;

use ApiMedia\Tgs\Data\Blocks\PictureData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Support\BlockSpec;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsCategory;
use ApiMedia\Tgs\Support\TgsModels;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

class Picture extends TgsBlock
{
    protected string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = 'iconpark-pictureone-o';

    public static function dataClass(): ?string
    {
        return PictureData::class;
    }

    public static function blockType(): ContentBlockType
    {
        return ContentBlockType::Picture;
    }

    public static function blockSpec(?string $image = null, ?string $alt = null): BlockSpec
    {
        return BlockSpec::for(static::class, ['alt' => $alt], files: $image === null ? [] : [$image]);
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return __('tgs::filament.tgs.blocks.picture.label');
    }

    public function getBlockFields(): array|Closure
    {
        return [
            static::mediaUuidHiddenField(),
            SpatieMediaLibraryFileUpload::make('image')
                ->when(($disk = static::contentMediaDisk()) !== null, fn (SpatieMediaLibraryFileUpload $field) => $field->disk($disk))
                ->model(fn (Get $get) => static::resolveMediaHostBlock($get('id')))
                ->required()
                ->label(__('tgs::filament.tgs.blocks.picture.fields.image.label'))
                ->customProperties(fn (Get $get): array => ['uuid' => $get('uuid')])
                ->collection(fn () => TgsModels::block()::getContentMediaCollectionName())
                ->downloadable()
                ->filterMediaUsing(
                    fn (Collection $media, Get $get): Collection => $media->where(
                        'custom_properties.uuid',
                        $get('uuid')
                    ),
                ),
            Tabs::make()->tabs([
                Tab::make('seo')
                    ->label(__('tgs::filament.tgs.blocks.picture.sections.seo.label'))
                    ->schema([
                        TextInput::make('alt')
                            ->label(__('tgs::filament.tgs.blocks.picture.fields.alt.label'))
                            ->hint(__('tgs::filament.tgs.blocks.picture.fields.alt.hint')),
                        TextInput::make('title')
                            ->label(__('tgs::filament.tgs.blocks.picture.fields.title.label'))
                            ->hint(__('tgs::filament.tgs.blocks.picture.fields.title.hint')),
                    ]),
                Tab::make('settings')
                    ->label(__('tgs::filament.tgs.blocks.picture.sections.settings.label'))
                    ->schema([
                        TextInput::make('caption')
                            ->label(__('tgs::filament.tgs.blocks.picture.fields.caption.label'))
                            ->hint(__('tgs::filament.tgs.blocks.picture.fields.caption.help')),
                        TextInput::make('navigate_to')
                            ->label(__('tgs::filament.tgs.blocks.picture.fields.navigate_to.label'))
                            ->helperText(__('tgs::filament.tgs.blocks.picture.fields.navigate_to.help')),
                        TextInput::make('media_url')
                            ->label(__('tgs::filament.tgs.blocks.picture.fields.media_url.label'))
                            ->helperText(__('tgs::filament.tgs.blocks.picture.fields.media_url.help')),
                        Checkbox::make('is_original')
                            ->label(__('tgs::filament.tgs.blocks.picture.fields.is_original.label'))
                            ->helperText(__('tgs::filament.tgs.blocks.picture.fields.is_original.help'))
                            ->default(false),
                        Checkbox::make('is_lightbox')
                            ->label(__('tgs::filament.tgs.blocks.picture.fields.is_lightbox.label'))
                            ->default(false),
                    ]),
                Tab::make('appearance')
                    ->label(__('tgs::filament.tgs.blocks.picture.sections.appearance.label'))
                    ->schema([
                        TextInput::make('classname')
                            ->label(__('tgs::filament.tgs.blocks.picture.fields.classname.label')),
                        Checkbox::make('is_rounded')
                            ->label(__('tgs::filament.tgs.blocks.picture.fields.is_rounded.label')),
                    ]),
            ]),
        ];
    }

    public static function toText(array $data): ?string
    {
        return (trim($data['caption'] ?? '') ?: null) ?? (trim($data['title'] ?? '') ?: null);
    }

    public function blockPreviewFields(): array
    {
        return ['alt'];
    }

    public function blockPreview(array $data, Block $block): ?string
    {
        $uuid = is_string($data['uuid'] ?? null) ? $data['uuid'] : null;

        if ($block->contentMediaWithUuid($uuid)->isEmpty()) {
            return __('tgs::filament.tgs.blocks.picture.preview_empty');
        }

        $alt = is_string($data['alt'] ?? null) ? trim($data['alt']) : '';
        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        $label = $alt !== '' ? $alt : ($title !== '' ? $title : null);

        return $label !== null
            ? __('tgs::filament.tgs.blocks.picture.preview_with_alt', ['alt' => $label])
            : __('tgs::filament.tgs.blocks.picture.preview');
    }

    public function blockCategory(): ?TgsCategory
    {
        return TgsCategory::media();
    }
}
