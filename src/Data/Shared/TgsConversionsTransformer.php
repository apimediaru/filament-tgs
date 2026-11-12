<?php

namespace ApiMedia\Tgs\Data\Shared;

use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

use function array_map;

/** A dictionary remains a JSON object even when no conversion is available. */
final class TgsConversionsTransformer implements Transformer
{
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): object
    {
        return (object) array_map(fn (TgsConversionData $conversion): array => $conversion->toArray(), $value);
    }
}
