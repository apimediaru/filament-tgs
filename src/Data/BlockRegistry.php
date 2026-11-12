<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data;

use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Support\TgsBlockRegistry;

/** Read adapter: the descriptors own the mapping. */
final class BlockRegistry
{
    /** @return array<string, class-string<ResolvesFromStored>> */
    public static function map(): array
    {
        return app(TgsBlockRegistry::class)->dataClasses();
    }

    /** @return class-string<ResolvesFromStored>|null */
    public static function resolve(string $type): ?string
    {
        return self::map()[$type] ?? null;
    }
}
