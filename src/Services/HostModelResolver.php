<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Services;

use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsHostRegistry;
use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Database\Eloquent\Model;

/** Resolves only core containers and explicitly registered application hosts. */
class HostModelResolver
{
    public function __construct(private readonly TgsHostRegistry $hosts) {}

    public function resolve(HostDescriptor $host): ?Model
    {
        $class = match ($host->containerType) {
            'block', TgsModels::block() => TgsModels::block(),
            'canvas', TgsModels::canvas() => TgsModels::canvas(),
            default => $this->hosts->resolve($host->containerType),
        };

        return $class !== null ? $class::query()->find($host->containerId) : null;
    }
}
