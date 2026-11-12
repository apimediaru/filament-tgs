<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Support\Tgs;

use ApiMedia\Tgs\Data\Concerns\ExtractsBlockData;
use Spatie\LaravelData\Data;

/**
 * Minimal block DTO for exercising the ExtractsBlockData trait in isolation.
 */
class FakeBlockData extends Data
{
    use ExtractsBlockData;

    public function __construct(public string $id, public ?string $title) {}

    public static function build(array $raw): self
    {
        return new self(id: self::pullId($raw), title: self::opt($raw, 'title'));
    }
}
