<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Events;

use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\LaravelSiteCacheInvalidator;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted after each call to forget a cache key by {@see LaravelSiteCacheInvalidator}, one event
 * per key. Pure observability/orchestration hook (logging, cache warming, cascades, tests) — the
 * invalidation itself does not depend on any subscriber. See ADR-021 "how to track".
 */
class TgsCacheInvalidated
{
    use Dispatchable;

    public function __construct(
        public readonly string $key,
        public readonly HostDescriptor $host,
    ) {}
}
