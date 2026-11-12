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

#[TypeScript('Collapse')]
class CollapseData extends Data implements ResolvesFromStored
{
    use ExtractsBlockData;

    #[EnumLiteralTypeScriptType(ContentBlockType::Collapse)]
    public ContentBlockType|string $type = ContentBlockType::Collapse;

    public function __construct(
        public string $id,
        public TgsSectionData $section,
        public ?string $title,
        /** @var list<object> */
        #[TgsBlockListType]
        public array $content,
        public ?bool $is_open,
    ) {}

    public static function fromStored(array $raw, Model $model): static
    {
        return new static(
            id: self::pullId($raw),
            section: TgsSectionData::fromStored($raw),
            title: self::opt($raw, 'title'),
            content: BlockCollectionData::fromStored($raw['content'] ?? [], $model),
            is_open: self::opt($raw, 'is_open'),
        );
    }
}
