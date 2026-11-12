<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Shared;

use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Named fields preserve the built-in contract; conversions exposes every available variant. */
#[TypeScript('Image')]
class TgsImageData extends Data
{
    /** @param array<string, TgsConversionData> $conversions */
    public function __construct(
        public ?TgsConversionData $original,
        public ?TgsConversionData $tiny,
        public ?TgsConversionData $thumb,
        public ?TgsConversionData $medium,
        public ?TgsConversionData $large,
        #[WithTransformer(TgsConversionsTransformer::class)]
        public array $conversions = [],
    ) {}

    public static function fromMedia(Media $media): self
    {
        $map = TgsConversionData::collectionFromMedia($media);

        return new self(
            original: $map['original'] ?? null,
            tiny: $map['tiny'] ?? null,
            thumb: $map['thumb'] ?? null,
            medium: $map['medium'] ?? null,
            large: $map['large'] ?? null,
            conversions: $map,
        );
    }
}
