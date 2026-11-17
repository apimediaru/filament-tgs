<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Data\Shared;

use ApiMedia\Tgs\Data\Concerns\ExtractsBlockData;
use ApiMedia\Tgs\Support\TgsCommonSettings;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript('Section')]
class TgsSectionData extends Data
{
    use ExtractsBlockData;

    public function __construct(
        public ?string $section_heading,
        public ?string $section_heading_tag,
        public ?string $section_heading_display,
        public ?string $section_heading_classname,
        public ?string $section_size,
        public ?string $section_position,
        public ?string $section_bg,
        public ?string $section_classname,
        public ?string $container_size,
        public ?string $container_position,
    ) {}

    public static function fromStored(array $raw): self
    {
        if (static::class === self::class) {
            $class = app(TgsCommonSettings::class)::dataClass();
            if ($class !== self::class) {
                return $class::fromStored($raw);
            }
        }

        return new static(
            section_heading: self::opt($raw, 'section_heading'),
            section_heading_tag: self::opt($raw, 'section_heading_tag'),
            section_heading_display: self::opt($raw, 'section_heading_display'),
            section_heading_classname: self::opt($raw, 'section_heading_classname'),
            section_size: self::opt($raw, 'section_size'),
            section_position: self::opt($raw, 'section_position'),
            section_bg: self::opt($raw, 'section_bg'),
            section_classname: self::opt($raw, 'section_classname'),
            container_size: self::opt($raw, 'container_size'),
            container_position: self::opt($raw, 'container_position'),
        );
    }
}
