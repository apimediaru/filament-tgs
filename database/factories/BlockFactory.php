<?php

namespace ApiMedia\Tgs\Database\Factories;

use ApiMedia\Tgs\Enums\BlockLockMode;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Support\TgsBlockRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

use function array_keys;

/**
 * @extends Factory<Block>
 */
class BlockFactory extends Factory
{
    protected $model = Block::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::ulid(),
            // A default block is standalone; structural items require their explicit states.
            'type' => $this->faker->randomElement(array_keys(app(TgsBlockRegistry::class)->dataClasses())),
            'data' => ['text' => $this->faker->sentence()],
            'name' => null,
            ...Block::unclaimedOwner(),
            'is_searchable' => true,
            'is_locked' => false,
            'lock_mode' => BlockLockMode::Cascade,
        ];
    }

    /**
     * A freshly created block is real, committed content by default (see
     * `2026_07_25_120000_backfill_placed_blocks_committed_at.php` / ADR-020) — `committed_at`
     * is not `fillable`, so it is stamped directly on the model after `make()`/`create()`
     * rather than through `definition()`'s mass-assignable array.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Block $block): void {
            $block->committed_at = now();
        });
    }

    /**
     * Explicit opt-in for ADR-020 draft/prune mechanics: `committed_at = null`. Registered
     * after {@see configure()}'s default `afterMaking` callback, so it always wins regardless
     * of where `->draft()` sits in the state chain relative to other states like `picture()`.
     */
    public function draft(): static
    {
        return $this->afterMaking(function (Block $block): void {
            $block->committed_at = null;
        });
    }

    /**
     * Mark the block as locked.
     */
    public function locked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_locked' => true,
        ]);
    }

    /**
     * Explicitly assign the orphan-owner convention (the factory's default,
     * but useful to state out loud when a test wants to be clear about it).
     */
    public function orphan(): static
    {
        return $this->state(fn (array $attributes): array => Block::unclaimedOwner());
    }

    /**
     * Set an explicit, readable id instead of the default generated ulid.
     */
    public function withId(string $id): static
    {
        return $this->state(fn (array $attributes): array => [
            'id' => $id,
        ]);
    }

    /**
     * Set the block's content type.
     */
    public function ofType(ContentBlockType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function content(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::Content, [
            'content' => $this->faker->paragraph(),
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public function picture(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::Picture, [
            'alt' => $this->faker->sentence(3),
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public function gallery(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::Gallery, [], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public function embedded(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::Embedded, [
            'source' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'ratio' => '16:9',
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public function column(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::Column, [], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public function doubleColumn(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::DoubleColumn, [], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public function accordion(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::Accordion, [
            'is_accordion' => true,
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public function collapse(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::Collapse, [], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public function tabs(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::Tabs, [], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public function code(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::Code, [
            'code' => $this->faker->word().'();',
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public function popup(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::Popup, [], $overrides);
    }

    /**
     * Not a top-level `BlockRegistry`-resolved type (only ever read as a nested item inside
     * a `tabs` block's `items` slot, via `TabItemData::fromStored()` directly) — still a
     * useful standalone factory state for tests exercising `TabItemData` shape in isolation.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function tabItem(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::TabItem, [
            'title' => $this->faker->words(2, true),
        ], $overrides);
    }

    /** @see tabItem() — same standalone-item caveat, for `accordion`'s `items` slot. */
    public function accordionItem(array $overrides = []): static
    {
        return $this->typed(ContentBlockType::AccordionItem, [
            'title' => $this->faker->words(2, true),
            'is_open' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $default
     * @param  array<string, mixed>  $overrides
     */
    private function typed(ContentBlockType $type, array $default, array $overrides): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type->value,
            'data' => [...$default, ...$overrides],
        ]);
    }
}
