<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Blocks;

use ApiMedia\Tgs\Data\Attributes\EnumLiteralTypeScriptType;
use ApiMedia\Tgs\Data\Concerns\ExtractsBlockData;
use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Support\TgsMedia;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('Embedded')]
class EmbeddedData extends Data implements ResolvesFromStored
{
    use ExtractsBlockData;

    #[EnumLiteralTypeScriptType(ContentBlockType::Embedded)]
    public ContentBlockType|string $type = ContentBlockType::Embedded;

    public function __construct(
        public string $id,
        public TgsSectionData $section,
        public ?string $source,
        public ?string $embedded_src,
        public ?string $ratio,
    ) {}

    public static function fromStored(array $raw, Model $model): static
    {
        return new static(
            id: self::pullId($raw),
            section: TgsSectionData::fromStored($raw),
            source: self::opt($raw, 'source'),
            embedded_src: TgsMedia::parseEmbeddedURL(self::opt($raw, 'source')),
            ratio: self::opt($raw, 'ratio'),
        );
    }
}
