<?php

namespace ApiMedia\Tgs\Data\Attributes;

use Attribute;
use BackedEnum;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

use function Illuminate\Support\enum_value;

/**
 * Emits an enum-typed property as a TS string-literal union of the given cases,
 * turning the property into a discriminant the frontend can narrow on. Pass `null`
 * as a member for a nullable property (e.g. the legacy template fallback).
 *
 * @example #[EnumLiteralTypeScriptType(Template::TOURNAMENT_LANDING)] => "tournament-landing"
 * @example #[EnumLiteralTypeScriptType(Template::SIMPLE, Template::TEXT_GRAPHIC_BUILDER, null)] => "simple" | "tgs" | null
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class EnumLiteralTypeScriptType extends LiteralTypeScriptType
{
    public function __construct(?BackedEnum ...$cases)
    {
        parent::__construct(implode(' | ', array_map(
            static fn (?BackedEnum $case) => $case === null ? 'null' : '"'.enum_value($case).'"',
            $cases,
        )));
    }
}
