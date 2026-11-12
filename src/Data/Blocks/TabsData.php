<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Blocks;

use ApiMedia\Tgs\Data\Attributes\EnumLiteralTypeScriptType;
use ApiMedia\Tgs\Data\Concerns\ExtractsBlockData;
use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Data\Shared\TabItemData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('Tabs')]
class TabsData extends Data implements ResolvesFromStored
{
    use ExtractsBlockData;

    #[EnumLiteralTypeScriptType(ContentBlockType::Tabs)]
    public ContentBlockType|string $type = ContentBlockType::Tabs;

    public function __construct(
        public string $id,
        public TgsSectionData $section,
        /** @var Collection<int, TabItemData> */
        #[DataCollectionOf(TabItemData::class)]
        public Collection $items,
    ) {}

    public static function fromStored(array $raw, Model $model): static
    {
        return new static(
            id: self::pullId($raw),
            section: TgsSectionData::fromStored($raw),
            items: collect(array_map(
                static fn (array $item): TabItemData => TabItemData::fromStored($item['data'] ?? $item, $model),
                $raw['items'] ?? [],
            )),
        );
    }
}
