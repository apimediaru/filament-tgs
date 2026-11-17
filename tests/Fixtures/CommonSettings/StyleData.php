<?php

namespace ApiMedia\Tgs\Tests\Fixtures\CommonSettings;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class StyleData extends Data
{
    public function __construct(public ?string $tone) {}
}
