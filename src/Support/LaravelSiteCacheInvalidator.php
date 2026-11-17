<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Events\TgsCacheInvalidated;
use ApiMedia\Tgs\Support\Contracts\SiteCacheInvalidator;
use Illuminate\Support\Facades\Cache;

final class LaravelSiteCacheInvalidator implements SiteCacheInvalidator
{
    public function invalidate(HostDescriptor $host, array $keys): void
    {
        foreach ($keys as $key) {
            Cache::forget($key);
            TgsCacheInvalidated::dispatch($key, $host);
        }
    }
}
