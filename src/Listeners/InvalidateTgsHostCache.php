<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Listeners;

use ApiMedia\Tgs\Events\TgsCacheInvalidated;
use ApiMedia\Tgs\Events\TgsContentChanged;
use ApiMedia\Tgs\Services\HostModelResolver;
use ApiMedia\Tgs\Support\Contracts\InvalidatesCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Capture keys while the host still exists, but forget them only after commit (ADR-021).
 * Deferring the entire listener loses keys when the transaction deletes the host.
 * Re-read surviving hosts after commit as well, preserving changes to their declared keys.
 */
class InvalidateTgsHostCache
{
    public function __construct(private readonly HostModelResolver $resolver) {}

    public function handle(TgsContentChanged $event): void
    {
        $host = $this->resolver->resolve($event->host);

        $keys = $host instanceof InvalidatesCache ? $host->cacheKeysToInvalidate() : [];

        DB::afterCommit(function () use ($event, $keys): void {
            $host = $this->resolver->resolve($event->host);
            if ($host instanceof InvalidatesCache) {
                $keys = [...$keys, ...$host->cacheKeysToInvalidate()];
            }

            foreach (array_unique($keys) as $key) {
                Cache::forget($key);
                TgsCacheInvalidated::dispatch($key, $event->host);
            }
        });
    }
}
