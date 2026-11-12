<?php

namespace ApiMedia\Tgs\Support\Concerns;

use Illuminate\Database\Eloquent\Model;

use function class_basename;
use function is_string;

/** @phpstan-require-extends Model */
trait HasTgsHostLabel
{
    public function tgsLabel(): string
    {
        $title = $this->getAttribute('title') ?? $this->getAttribute('name');

        return is_string($title) && $title !== '' ? $title : class_basename($this).' #'.$this->getKey();
    }
}
