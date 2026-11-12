<?php

namespace ApiMedia\Tgs\Support\Contracts;

use ApiMedia\Tgs\Listeners\InvalidateTgsHostCache;

/**
 * A host whose content backs one or more cached read-outputs. When its TGS content changes,
 * the generic listener {@see InvalidateTgsHostCache} forgets exactly these keys —
 * the model is the single source of truth for its own cache keys (no central map). A host that
 * owns no cache returns []; a host whose cache lives on an OWNER delegates to it.
 */
interface InvalidatesCache
{
    /** @return array<int, string> */
    public function cacheKeysToInvalidate(): array;
}
