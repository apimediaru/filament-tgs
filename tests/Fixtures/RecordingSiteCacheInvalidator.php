<?php

namespace ApiMedia\Tgs\Tests\Fixtures;

use ApiMedia\Tgs\Support\Contracts\SiteCacheInvalidator;
use ApiMedia\Tgs\Support\HostDescriptor;

final class RecordingSiteCacheInvalidator implements SiteCacheInvalidator
{
    /** @var list<array{HostDescriptor, non-empty-list<string>}> */
    public array $calls = [];

    public function invalidate(HostDescriptor $host, array $keys): void
    {
        $this->calls[] = [$host, $keys];
    }
}
