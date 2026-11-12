<?php

namespace ApiMedia\Tgs\Support\Contracts;

/**
 * UUID column with a findByUuid() lookup; the column name comes from getUuidKeyName().
 */
interface HasUuid
{
    public function getUuid(): ?string;

    public static function getUuidKeyName(): string;

    public static function findByUuid(string $uuid): ?static;
}
