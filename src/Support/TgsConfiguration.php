<?php

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\TgsPlugin;

/** Application-boot configuration only; request mutations stay in scoped registries. */
final class TgsConfiguration
{
    /** @var array<string, TgsPlugin> */
    public array $plugins = [];

    public function register(string $panel, TgsPlugin $plugin): void
    {
        $this->plugins[$panel] = $plugin;
    }
}
