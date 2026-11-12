<?php

namespace ApiMedia\Tgs\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/** @internal Identifies an immutable package fallback that a site mapper may replace. */
final readonly class TgsDefaultAbility
{
    public function __construct(private bool $allowed) {}

    public function allows(Authenticatable $user): bool
    {
        return $this->allowed;
    }
}
