<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Shared;

use ApiMedia\Tgs\Support\TgsMedia;
use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('Conversion')]
class TgsConversionData extends Data
{
    public function __construct(
        public ?string $url,
        public ?int $width,
        public ?int $height,
        public ?string $hash,
        public ?float $ratio,
        public ?string $aspect_ratio,
        // padding-top-процент в формате генератора ImageData::getPaddingTop() — строка «66.68…%».
        // Не float: фронт (TGS/Components/Picture.vue) по строке с «%» ставит корректный
        // --aspect-ratio-placeholder, а число трактует как CSS aspect-ratio — ломает геометрию.
        public ?string $pt,
    ) {}

    public static function fromImageData(array $d): self
    {
        return new self(
            url: $d['url'] ?? null,
            width: isset($d['width']) ? (int) $d['width'] : null,
            height: isset($d['height']) ? (int) $d['height'] : null,
            hash: $d['hash'] ?? null,
            ratio: isset($d['ratio']) ? (float) $d['ratio'] : null,
            aspect_ratio: isset($d['aspect_ratio']) ? (string) $d['aspect_ratio'] : null,
            pt: isset($d['pt']) ? (string) $d['pt'] : null,
        );
    }

    /** @return array<string, self> */
    public static function collectionFromMedia(Media $media): array
    {
        return collect(TgsMedia::getMediaConversionsOutput($media))
            ->filter() // drop conversions that failed to load (null)
            ->map(fn (array $d) => self::fromImageData($d))
            ->all();
    }
}
