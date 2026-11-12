<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Blocks;

use ApiMedia\Tgs\Data\Attributes\EnumLiteralTypeScriptType;
use ApiMedia\Tgs\Data\Attributes\TgsBlockListType;
use ApiMedia\Tgs\Data\BlockCollectionData;
use ApiMedia\Tgs\Data\Concerns\ExtractsBlockData;
use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('Column')]
class ColumnData extends Data implements ResolvesFromStored
{
    use ExtractsBlockData;

    #[EnumLiteralTypeScriptType(ContentBlockType::Column)]
    public ContentBlockType|string $type = ContentBlockType::Column;

    public function __construct(
        public string $id,
        public TgsSectionData $section,
        /** @var list<object> */
        #[TgsBlockListType]
        public array $content,
    ) {}

    public static function fromStored(array $raw, Model $model): static
    {
        return new static(
            id: self::pullId($raw),
            section: TgsSectionData::fromStored($raw),
            // Recursive: nested children get the same typed-or-degrade treatment as top-level
            // blocks. Absent/null → []; present-but-non-array → TypeError (array param) →
            // the whole Column degrades to UnknownBlockData upstream (ADR-013 degrade contract).
            content: BlockCollectionData::fromStored($raw['content'] ?? [], $model),
        );
    }
}
