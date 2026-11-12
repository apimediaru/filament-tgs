<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ResolvesFromStored
{
    public static function fromStored(array $raw, Model $model): static;
}
