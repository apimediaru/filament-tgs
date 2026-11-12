<?php

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Models\Canvas;
use Illuminate\Support\Facades\Gate;

use function app;
use function is_array;

/** Abilities belong to TGS; their assignment belongs to the host application. */
final class TgsAuthorization
{
    public static function registerDefaults(): void
    {
        foreach (['tgs.access', 'tgs.manageLibrary', 'tgs.manageLocks', 'tgs.deleteEverywhere'] as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, [new TgsDefaultAbility($ability === 'tgs.access'), 'allows']);
            }
        }
    }

    public static function authorizeCanvas(Canvas $canvas): void
    {
        Gate::authorize('tgs.access');
        if (($canvas->kind === 'named' && $canvas->key === TgsNamedCanvas::BlockLibrary->value)
            || ($canvas->kind === 'host' && app(TgsHostRegistry::class)->keyFor((string) $canvas->host_type) === 'collection')) {
            Gate::authorize('tgs.manageLibrary');
        }
    }

    /** Register a site default without replacing an explicit site Gate. */
    public static function define(string $ability, callable $callback): void
    {
        $current = Gate::abilities()[$ability] ?? null;
        if ($current === null || (is_array($current) && ($current[0] ?? null) instanceof TgsDefaultAbility)) {
            Gate::define($ability, $callback);
        }
    }

    /** @param array<string, string> $permissions */
    public static function permissions(array $permissions): void
    {
        foreach ($permissions as $ability => $permission) {
            self::define($ability, $ability === $permission
                ? fn ($user): bool => false
                : fn ($user): bool => Gate::forUser($user)->allows($permission));
        }
    }
}
