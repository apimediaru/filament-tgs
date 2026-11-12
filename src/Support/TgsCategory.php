<?php

namespace ApiMedia\Tgs\Support;

use InvalidArgumentException;

use function preg_match;

final readonly class TgsCategory
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $icon = null,
        public int $order = 0,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_]*$/D', $key)) {
            throw new InvalidArgumentException('Invalid TGS category key: '.$key);
        }
    }

    public static function content(): self
    {
        return new self('content', __('tgs::filament.tgs.categories.content'), 'heroicon-o-document-text', 10);
    }

    public static function media(): self
    {
        return new self('media', __('tgs::filament.tgs.categories.media'), 'heroicon-o-photo', 20);
    }

    public static function layout(): self
    {
        return new self('layout', __('tgs::filament.tgs.categories.layout'), 'heroicon-o-squares-2x2', 30);
    }
}
