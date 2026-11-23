<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Support;

use Closure;
use InvalidArgumentException;

/** A reusable recipe for creating new blocks with a host factory. */
final readonly class BlockSpec
{
    /**
     * @param  array<string, mixed>|Closure(): array<string, mixed>  $data
     * @param  array<string, BlockSpec|list<BlockSpec>>  $slots
     * @param  list<string>  $files
     */
    private function __construct(
        public string $type,
        private array|Closure $data,
        public array $slots,
        public array $files,
        public int $quantity,
    ) {}

    /**
     * @param  array<string, mixed>|Closure(): array<string, mixed>  $data
     * @param  array<string, BlockSpec|list<BlockSpec>>  $slots
     * @param  list<string>  $files  Local file paths, in the order to attach them.
     */
    public static function of(string $type, array|Closure $data = [], array $slots = [], array $files = []): self
    {
        return new self($type, $data, $slots, $files, 1);
    }

    public function count(int $quantity): self
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException('A block count cannot be negative.');
        }

        return new self($this->type, $this->data, $this->slots, $this->files, $quantity);
    }

    /** @internal @return array<string, mixed> */
    public function data(): array
    {
        return $this->data instanceof Closure ? ($this->data)() : $this->data;
    }
}
