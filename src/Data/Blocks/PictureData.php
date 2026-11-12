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
use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('Picture')]
class PictureData extends Data implements ResolvesFromStored
{
    use ExtractsBlockData;

    #[EnumLiteralTypeScriptType(ContentBlockType::Picture)]
    public ContentBlockType|string $type = ContentBlockType::Picture;

    public function __construct(
        public string $id,
        public TgsSectionData $section,
        public ?TgsImageData $image,
        public ?string $alt,
        public ?string $title,
        public ?string $caption,
        public ?string $navigate_to,
        public ?string $media_url,
        public ?bool $is_original,
        public ?bool $is_lightbox,
        public ?bool $is_rounded,
        public ?string $classname,
    ) {}

    public static function fromStored(array $raw, Model $model): static
    {
        return new static(
            id: self::pullId($raw),
            section: TgsSectionData::fromStored($raw),
            image: self::resolveImage($raw, $model),
            alt: self::opt($raw, 'alt'),
            title: self::opt($raw, 'title'),
            caption: self::opt($raw, 'caption'),
            navigate_to: self::opt($raw, 'navigate_to'),
            media_url: self::opt($raw, 'media_url'),
            is_original: self::opt($raw, 'is_original'),
            is_lightbox: self::opt($raw, 'is_lightbox'),
            is_rounded: self::opt($raw, 'is_rounded'),
            classname: self::opt($raw, 'classname'),
        );
    }

    // Final cleanup: fromStored владеет сырым uuid→media трансформом (экс-Picture::transformBlockValue).
    // Хост без media (About рендерит блоки на Sushi-модели Virtual) — просто нет картинки, не ошибка.
    private static function resolveImage(array $raw, Model $model): ?TgsImageData
    {
        $uuid = $raw['uuid'] ?? null;

        if (blank($uuid)) {
            return null;
        }

        $blockId = $raw['id'] ?? null;
        $block = $blockId !== null ? TgsModels::block()::query()->whereKey($blockId)->first() : null;
        $owner = $block ?? $model;

        if (! $owner instanceof HasMedia) {
            return null;
        }

        $media = $owner->media->where('custom_properties.uuid', $uuid)->first();

        return $media instanceof Media ? TgsImageData::fromMedia($media) : null;
    }
}
