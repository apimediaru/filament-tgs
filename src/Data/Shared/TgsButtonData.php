<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Shared;

use ApiMedia\Tgs\Data\Concerns\ExtractsBlockData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('Button')]
class TgsButtonData extends Data
{
    use ExtractsBlockData;

    public function __construct(
        public ?string $button_label,
        public ?string $button_theme,
        public ?string $button_variant,
        public ?string $button_class,
        public ?string $button_alignment,
    ) {}

    public static function fromStored(array $raw): self
    {
        return new self(
            button_label: self::opt($raw, 'button_label'),
            button_theme: self::opt($raw, 'button_theme'),
            button_variant: self::opt($raw, 'button_variant'),
            button_class: self::opt($raw, 'button_class'),
            button_alignment: self::opt($raw, 'button_alignment'),
        );
    }
}
