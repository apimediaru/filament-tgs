<?php

namespace ApiMedia\Tgs\Tests\Fixtures\CommonSettings;

use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SiteSection extends TgsSectionData
{
    public ?string $section_note = null;

    public ?StyleData $section_style = null;

    public static function fromStored(array $raw): static
    {
        /** @var static $dto */
        $dto = parent::fromStored($raw);
        $dto->section_note = $raw['section_note'] ?? null;
        $dto->section_style = isset($raw['section_style']) ? new StyleData($raw['section_style']['tone'] ?? null) : null;

        return $dto;
    }
}
