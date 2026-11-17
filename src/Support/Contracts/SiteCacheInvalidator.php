<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Support\Contracts;

use ApiMedia\Tgs\Support\HostDescriptor;

interface SiteCacheInvalidator
{
    /** @param  non-empty-list<string>  $keys */
    public function invalidate(HostDescriptor $host, array $keys): void;
}
