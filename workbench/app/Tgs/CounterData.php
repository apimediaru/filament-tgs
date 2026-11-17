<?php

namespace Workbench\App\Tgs;

use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CounterData extends Data implements ResolvesFromStored
{
    #[LiteralTypeScriptType('"'.Counter::TYPE.'"')]
    public string $type = Counter::TYPE;

    public function __construct(
        public string $id,
        public TgsSectionData $section,
        public ?int $count,
    ) {}

    public static function fromStored(array $raw, Model $model): static
    {
        $count = $raw['count'] ?? null;

        return new static(
            (string) ($raw['id'] ?? ''),
            TgsSectionData::fromStored($raw),
            is_int($count) ? $count : null,
        );
    }
}
