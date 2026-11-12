<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Shared;

use ApiMedia\Tgs\Data\Attributes\TgsBlockListType;
use ApiMedia\Tgs\Data\BlockCollectionData;
use ApiMedia\Tgs\Data\Concerns\ExtractsBlockData;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('AccordionItem')]
class AccordionItemData extends Data
{
    use ExtractsBlockData;

    public function __construct(
        public ?string $title,
        public ?bool $is_open,
        /** @var list<object> */
        #[TgsBlockListType]
        public array $content,
    ) {}

    public static function fromStored(array $item, Model $model): self
    {
        return new self(
            title: self::opt($item, 'title'),
            is_open: self::opt($item, 'is_open'),
            content: BlockCollectionData::fromStored($item['content'] ?? [], $model),
        );
    }
}
