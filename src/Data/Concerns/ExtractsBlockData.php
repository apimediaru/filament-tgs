<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Concerns;

use Illuminate\Support\Str;
use LogicException;

trait ExtractsBlockData
{
    public static function from(...$args): static
    {
        throw new LogicException(static::class.': use fromStored(); Data::from() bypasses block transforms.');
    }

    protected static function pullId(array $raw): string
    {
        return (string) ($raw['id'] ?? Str::ulid());
    }

    /**
     * Pull a nullable value: absent OR stored-null → null; present → its value.
     * Every declared block/section field is always emitted (null when absent) so the
     * payload has a fixed, self-documenting shape — more informative and reliable than
     * omitting fields (which also made an all-absent nested DTO serialize to `[]`).
     */
    protected static function opt(array $raw, string $key): mixed
    {
        return $raw[$key] ?? null;
    }
}
