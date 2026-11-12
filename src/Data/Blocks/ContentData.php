<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Blocks;

use ApiMedia\Tgs\Data\Attributes\EnumLiteralTypeScriptType;
use ApiMedia\Tgs\Data\Concerns\ExtractsBlockData;
use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('Content')]
class ContentData extends Data implements ResolvesFromStored
{
    use ExtractsBlockData;

    #[EnumLiteralTypeScriptType(ContentBlockType::Content)]
    public ContentBlockType|string $type = ContentBlockType::Content;

    public function __construct(
        public string $id,
        public TgsSectionData $section,
        public ?string $content,
    ) {}

    public static function fromStored(array $raw, Model $model): static
    {
        return new static(
            id: self::pullId($raw),
            section: TgsSectionData::fromStored($raw),
            content: self::opt($raw, 'content'),
        );
    }
}
