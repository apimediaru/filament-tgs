<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class BlockReuseLookupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function placements_returns_every_placement_of_a_reused_block(): void
    {
        $block = Block::factory()->picture()->withId('01J000000000000000000AAAAA')->create([
            'owner_container_type' => 'news',
            'owner_container_id' => '1',
        ]);

        BlockPlacement::forceCreate([
            'container_type' => 'news', 'container_id' => '1',
            'slot' => 'content_builder_value', 'rank' => '000000',
            'block_id' => $block->id,
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'page', 'container_id' => '7',
            'slot' => 'template_data.builder', 'rank' => '000002',
            'block_id' => $block->id,
        ]);

        $this->assertCount(2, $block->placements);
        $this->assertEqualsCanonicalizing(
            ['news', 'page'],
            $block->placements->pluck('container_type')->all(),
        );
    }

    #[Test]
    public function is_searchable_defaults_to_true(): void
    {
        $block = Block::factory()->picture()->withId('01J000000000000000000BBBBB')->create([
            'owner_container_type' => 'news',
            'owner_container_id' => '1',
        ]);

        $this->assertTrue($block->fresh()->is_searchable);
    }

    #[Test]
    public function searchable_scope_excludes_blocks_flagged_out(): void
    {
        $visible = Block::factory()->picture()->withId('01J000000000000000000CCCCC')->create([
            'owner_container_type' => 'news', 'owner_container_id' => '1',
        ]);
        $hidden = Block::factory()->picture()->withId('01J000000000000000000DDDDD')->create([
            'owner_container_type' => 'news', 'owner_container_id' => '1',
            'is_searchable' => false,
        ]);

        $ids = Block::searchable()->pluck('id')->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }
}
