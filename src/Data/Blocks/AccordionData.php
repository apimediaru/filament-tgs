<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Blocks;

use ApiMedia\Tgs\Data\Attributes\EnumLiteralTypeScriptType;
use ApiMedia\Tgs\Data\Concerns\ExtractsBlockData;
use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Data\Shared\AccordionItemData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('Accordion')]
class AccordionData extends Data implements ResolvesFromStored
{
    use ExtractsBlockData;

    #[EnumLiteralTypeScriptType(ContentBlockType::Accordion)]
    public ContentBlockType|string $type = ContentBlockType::Accordion;

    public function __construct(
        public string $id,
        public TgsSectionData $section,
        public ?bool $is_accordion,
        /** @var Collection<int, AccordionItemData> */
        #[DataCollectionOf(AccordionItemData::class)]
        public Collection $items,
    ) {}

    public static function fromStored(array $raw, Model $model): static
    {
        return new static(
            id: self::pullId($raw),
            section: TgsSectionData::fromStored($raw),
            is_accordion: self::opt($raw, 'is_accordion'),
            items: collect(array_map(
                static fn (array $item): AccordionItemData => AccordionItemData::fromStored($item['data'] ?? $item, $model),
                $raw['items'] ?? [],
            )),
        );
    }
}
