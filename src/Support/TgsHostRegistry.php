<?php

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Support\Contracts\TgsHost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

use function array_filter;
use function in_array;
use function is_subclass_of;
use function preg_match;

/** Request-scoped registration. The host app owns the model list and identity. */
final class TgsHostRegistry
{
    /** @var array<string, class-string<Model&TgsHost>> */
    private array $models = [];

    /** @var array<string, bool> */
    private array $reusable = [];

    /** @var array<string, bool> */
    private array $staging = [];

    /** @param list<class-string> $models */
    public function hosts(array $models, bool $reusable = true, bool $staging = false): self
    {
        foreach ($models as $class) {
            if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, TgsHost::class)) {
                throw new InvalidArgumentException('TGS host must be an Eloquent model implementing TgsHost: '.$class);
            }
            $key = $class::tgsHostKey();
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $key) || in_array($key, ['block', 'canvas', 'settings'], true)) {
                throw new InvalidArgumentException('Invalid or reserved TGS host key: '.$key);
            }
            if (isset($this->models[$key]) && $this->models[$key] !== $class) {
                throw new InvalidArgumentException('Duplicate TGS host key: '.$key);
            }
            $this->models[$key] = $class;
            $this->reusable[$key] = $reusable;
            $this->staging[$key] = $staging;
        }

        return $this;
    }

    /** @return class-string<Model&TgsHost>|null */
    public function resolve(string $type): ?string
    {
        $mapped = Relation::getMorphedModel($type);
        if (isset($this->models[$type])) {
            return $mapped === null || $mapped === $this->models[$type] ? $this->models[$type] : null;
        }
        foreach ($this->models as $class) {
            if ($type === $class || $mapped === $class) {
                return $class;
            }
        }

        return null;
    }

    public function keyFor(string $type): ?string
    {
        $class = $this->resolve($type);

        return $class !== null ? $class::tgsHostKey() : null;
    }

    public function isStaging(string $type): bool
    {
        $key = $this->keyFor($type);

        return $key !== null && ($this->staging[$key] ?? false);
    }

    /** @return array<string, class-string<Model&TgsHost>> */
    public function reusable(): array
    {
        return array_filter($this->models, fn (string $class): bool => $this->reusable[$class::tgsHostKey()]);
    }
}
