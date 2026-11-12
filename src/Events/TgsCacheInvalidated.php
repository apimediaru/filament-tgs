<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Events;

use ApiMedia\Tgs\Listeners\InvalidateTgsHostCache;
use ApiMedia\Tgs\Support\HostDescriptor;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted AFTER a cache key was forgotten by {@see InvalidateTgsHostCache}, one event
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
