<?php

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\TgsPlugin;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Application-boot configuration only; request mutations stay in scoped registries. */
final class TgsConfiguration
{
    /** @var array<string, TgsPlugin> */
    public array $plugins = [];

    /** @return class-string<TgsCommonSettings> */
    public function commonSettingsClass(): string
    {
        $classes = array_values(array_unique(array_filter(array_map(
            fn ($plugin) => $plugin->commonSettingsClass,
            $this->plugins,
        ))));
        if (count($classes) > 1) {
            throw new \LogicException('Conflicting common settings: '.implode(', ', $classes));
        }
        $class = $classes[0] ?? TgsCommonSettings::class;
        if (! is_a($class::dataClass(), TgsSectionData::class, true)) {
            throw new \LogicException('Common DTO must extend TgsSectionData');
        }

        if (! (new \ReflectionClass($class))->isInstantiable()) {
            throw new \LogicException('Common settings must be concrete');
        }
        $dto = new \ReflectionClass($class::dataClass());
        if (! $dto->isInstantiable() || $dto->getAttributes(TypeScript::class) === []) {
            throw new \LogicException('Common DTO must be concrete and explicitly marked with #[TypeScript]');
        }

        return $class;
    }

    public function register(string $panel, TgsPlugin $plugin): void
    {
        $this->plugins[$panel] = $plugin;
    }
}
