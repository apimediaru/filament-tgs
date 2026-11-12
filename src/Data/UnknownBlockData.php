<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data;

use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('UnknownBlock')]
class UnknownBlockData extends Data
{
    public function __construct(
        public string $type,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public array $data,
    ) {}

    public static function fromLegacy(string $type, array $data): self
    {
        // '@class' is a historical dispatch key from the pre-Final-cleanup legacy pipeline
        // (removed); already-stored content may still carry it, so the fallback path strips it.
        $data = Arr::except($data, ['@class']);

        return new self(type: $type, data: $data);
    }
}
