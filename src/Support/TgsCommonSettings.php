<?php

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Data\Shared\TgsSectionData;

class TgsCommonSettings
{
    /** @return class-string<TgsSectionData> */
    public static function dataClass(): string
    {
        return TgsSectionData::class;
    }

    public function heading(array $fields): array
    {
        return $fields;
    }

    public function section(array $fields): array
    {
        return $fields;
    }

    public function container(array $fields): array
    {
        return $fields;
    }
}
