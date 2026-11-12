<?php

namespace ApiMedia\Tgs\Support\Contracts;

use ApiMedia\Tgs\Models\Contracts\HasCanvases;

interface TgsHost extends HasCanvases
{
    public static function tgsHostKey(): string;

    public function tgsLabel(): string;

    public function tgsEditUrl(): ?string;
}
