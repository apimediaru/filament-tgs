<?php

namespace ApiMedia\Tgs\Data\Attributes;

use Attribute;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Emits a property as the generated TGS block union list: `Array<ApiMedia.Tgs.Data.Block>`
 * (add ` | null` with `nullable: true`). The union's fully-qualified TS name lives here only —
 * no magic-string repetition across the content DTOs / template blocks (see ADR-013).
 *
 * @example #[TgsBlockListType]               => Array<ApiMedia.Tgs.Data.Block>
 * @example #[TgsBlockListType(nullable: true)] => Array<ApiMedia.Tgs.Data.Block> | null
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class TgsBlockListType extends LiteralTypeScriptType
{
    public function __construct(bool $nullable = false)
    {
        parent::__construct('Array<ApiMedia.Tgs.Data.Block>'.($nullable ? ' | null' : ''));
    }
}
