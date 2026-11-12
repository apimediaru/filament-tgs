<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Blocks;

use ApiMedia\Tgs\Data\Attributes\EnumLiteralTypeScriptType;
use ApiMedia\Tgs\Data\Concerns\ExtractsBlockData;
use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Data\Shared\TgsImageData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('Gallery')]
class GalleryData extends Data implements ResolvesFromStored
{
    use ExtractsBlockData;

    #[EnumLiteralTypeScriptType(ContentBlockType::Gallery)]
    public ContentBlockType|string $type = ContentBlockType::Gallery;

    public function __construct(
        public string $id,
        public TgsSectionData $section,
        /** @var Collection<int, TgsImageData> */
        #[DataCollectionOf(TgsImageData::class)]
        public Collection $images,
        public ?string $aspect_ratio,
        public int|string|null $slide_count,
        public ?bool $is_lightbox,
        public ?bool $is_original,
        public ?bool $is_cropped,
        public ?bool $is_rounded,
        public ?bool $is_centered,
        public ?bool $is_looped,
        public ?bool $is_rewound,
    ) {}

    public static function fromStored(array $raw, Model $model): static
    {
        return new static(
            id: self::pullId($raw),
            section: TgsSectionData::fromStored($raw),
            images: self::resolveImages($raw, $model),
            aspect_ratio: self::opt($raw, 'aspect_ratio'),
            slide_count: self::normalizeSlideCount(self::opt($raw, 'slide_count')),
            is_lightbox: self::opt($raw, 'is_lightbox'),
            is_original: self::opt($raw, 'is_original'),
            is_cropped: self::opt($raw, 'is_cropped'),
            is_rounded: self::opt($raw, 'is_rounded'),
            is_centered: self::opt($raw, 'is_centered'),
            is_looped: self::opt($raw, 'is_looped'),
            is_rewound: self::opt($raw, 'is_rewound'),
        );
    }

    // Final cleanup: fromStored владеет сырым uuid→media[] трансформом (экс-Gallery::transformBlockValue).
    /** @return Collection<int, TgsImageData> */
    private static function resolveImages(array $raw, Model $model): Collection
    {
        $uuid = $raw['uuid'] ?? null;

        if (blank($uuid)) {
            return collect();
        }

        $blockId = $raw['id'] ?? null;
        $block = $blockId !== null ? TgsModels::block()::query()->whereKey($blockId)->first() : null;
        $owner = $block ?? $model;

        if (! $owner instanceof HasMedia) {
            return collect();
        }

        return $owner->media
            ->where('custom_properties.uuid', $uuid)
            ->sortBy('order_column')
            ->values()
            ->map(fn (Media $media) => TgsImageData::fromMedia($media));
    }

    private static function normalizeSlideCount(int|string|null $value): int|string|null
    {
        return is_numeric($value) ? (int) $value : $value;
    }
}
