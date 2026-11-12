<?php

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockDeletion;
use ApiMedia\Tgs\Models\BlockMedia;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\BlockVersion;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Models\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

use function config;
use function is_a;
use function is_string;

/** Consistent model selection for queries, relations and media ownership. */
final class TgsModels
{
    /** @return class-string<Block> */
    public static function block(): string
    {
        return self::resolve('block', Block::class);
    }

    /** @return class-string<Canvas> */
    public static function canvas(): string
    {
        return self::resolve('canvas', Canvas::class);
    }

    /** @return class-string<BlockPlacement> */
    public static function placement(): string
    {
        return self::resolve('placement', BlockPlacement::class);
    }

    /** @return class-string<BlockVersion> */
    public static function version(): string
    {
        return self::resolve('version', BlockVersion::class);
    }

    /** @return class-string<BlockDeletion> */
    public static function deletion(): string
    {
        return self::resolve('deletion', BlockDeletion::class);
    }

    /** @return class-string<Collection> */
    public static function collection(): string
    {
        return self::resolve('collection', Collection::class);
    }

    /** @return class-string<BlockMedia> */
    public static function block_media(): string
    {
        return self::resolve('block_media', BlockMedia::class);
    }

    /** @return class-string<Model> */
    public static function author(): string
    {
        $guard = config('auth.defaults.guard');
        $provider = config('auth.guards.'.$guard.'.provider');
        $class = config('tgs.models.author', config('auth.providers.'.$provider.'.model'));
        if (! is_string($class) || ! is_a($class, Model::class, true)) {
            throw new InvalidArgumentException('Configure tgs.models.author as an Eloquent model.');
        }

        return $class;
    }

    public static function authorLabel(?Model $author): ?string
    {
        if ($author === null) {
            return null;
        }
        foreach (['display_name', 'name'] as $attribute) {
            if ($author->hasAttribute($attribute)) {
                $label = $author->getAttribute($attribute);
                if (is_string($label) && $label !== '') {
                    return $label;
                }
            }
        }

        return (string) $author->getKey();
    }

    /** @template T of Model
     * @param  class-string<T>  $base
     * @return class-string<T>
     */
    private static function resolve(string $key, string $base): string
    {
        $class = config('tgs.models.'.$key, $base);
        if (! is_string($class) || ! is_a($class, $base, true)) {
            throw new InvalidArgumentException('Invalid TGS model for '.$key);
        }

        return $class;
    }
}
