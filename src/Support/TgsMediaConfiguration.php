<?php

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Models\Block;
use Closure;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Request-scoped media conversion customization supplied by the host application. */
final class TgsMediaConfiguration
{
    private ?Closure $conversions = null;

    /** @param Closure(Block, ?Media): void $callback */
    public function mediaConversions(Closure $callback): self
    {
        $this->conversions = $callback;

        return $this;
    }

    public function register(Block $block, ?Media $media): bool
    {
        if ($this->conversions === null) {
            return false;
        }
        ($this->conversions)($block, $media);

        return true;
    }
}
