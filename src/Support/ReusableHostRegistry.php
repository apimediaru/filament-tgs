<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Support;

use Illuminate\Database\Eloquent\Model;

/** Adapter for reusable-source lookup; registration is owned by the host app. */
final class ReusableHostRegistry
{
    /** @return array<string, class-string<Model>> */
    public static function map(): array
    {
        return app(TgsHostRegistry::class)->reusable();
    }

    /** @return class-string<Model>|null */
    public static function resolve(string $alias): ?string
    {
        return self::map()[$alias] ?? null;
    }
}
