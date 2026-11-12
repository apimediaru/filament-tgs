<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Listeners;

use ApiMedia\Tgs\Events\TgsCacheInvalidated;
use ApiMedia\Tgs\Events\TgsContentChanged;
use ApiMedia\Tgs\Services\HostModelResolver;
use ApiMedia\Tgs\Support\Contracts\InvalidatesCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Cache;

/**
 * The single generic consumer of {@see TgsContentChanged}: resolves the changed host, forgets every
 * key it self-declares via {@see InvalidatesCache}, and emits {@see CacheInvalidated} per key. Any
 * TGS mutation path that dispatches TgsContentChanged (CanvasPersistence via TgsContentChangeNotifier,
 * CanvasPersistence-driven mutations) funnels through here — no per-cache bespoke listener. Auto-discovered by the
 * handle() type-hint, like InvalidateCacheOnMediaChange (which also routes block media changes here).
 * See ADR-021.
 *
 * Implements {@see ShouldHandleEventsAfterCommit}: TgsContentChanged is dispatched from inside the
 * mutation's DB::transaction (CanvasPersistence::signalChanged), so forgetting
 * the cache synchronously would run before COMMIT — a concurrent read on another connection can't see
 * the uncommitted row and would repopulate the cache with the stale pre-edit value, with no later
 * invalidation to catch it. Deferring to after-commit closes that window. If no transaction is open,
 * Laravel handles the event immediately as usual.
 */
class InvalidateTgsHostCache implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly HostModelResolver $resolver) {}

    public function handle(TgsContentChanged $event): void
    {
        $host = $this->resolver->resolve($event->host);

        if (! $host instanceof InvalidatesCache) {
            return;
        }

        foreach ($host->cacheKeysToInvalidate() as $key) {
            Cache::forget($key);
            TgsCacheInvalidated::dispatch($key, $event->host);
        }
    }
}
