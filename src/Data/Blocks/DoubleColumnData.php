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

#[TypeScript('DoubleColumn')]
class DoubleColumnData extends Data implements ResolvesFromStored
{
    use ExtractsBlockData;

    #[EnumLiteralTypeScriptType(ContentBlockType::DoubleColumn)]
    public ContentBlockType|string $type = ContentBlockType::DoubleColumn;

    public function __construct(
        public string $id,
        public TgsSectionData $section,
        /** @var list<object> */
        #[TgsBlockListType]
        public array $left,
        /** @var list<object> */
        #[TgsBlockListType]
        public array $right,
        public ?string $left_column_width,
        public ?string $right_column_width,
        public ?string $justification,
        public ?bool $is_aligned_vertically,
        public ?bool $is_swapped,
    ) {}

    public static function fromStored(array $raw, Model $model): static
    {
        return new static(
            id: self::pullId($raw),
            section: TgsSectionData::fromStored($raw),
            left: BlockCollectionData::fromStored($raw['left'] ?? [], $model),
            right: BlockCollectionData::fromStored($raw['right'] ?? [], $model),
            left_column_width: self::opt($raw, 'left_column_width'),
            right_column_width: self::opt($raw, 'right_column_width'),
            justification: self::opt($raw, 'justification'),
            is_aligned_vertically: self::opt($raw, 'is_aligned_vertically'),
            is_swapped: self::opt($raw, 'is_swapped'),
        );
    }
}
