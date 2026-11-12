<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class BlockSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function block_persists_with_ulid_pk_and_json_data(): void
    {
        $block = Block::factory()->ofType(ContentBlockType::Content)->withId('01J000000000000000000AAAAA')->create([
            'data' => ['section' => ['heading' => 'Hi']],
            'owner_container_type' => 'news',
            'owner_container_id' => '1',
        ]);

        $this->assertDatabaseHas('tgs_blocks', ['id' => '01J000000000000000000AAAAA', 'type' => 'content']);
        $this->assertSame(['section' => ['heading' => 'Hi']], $block->fresh()->data);
    }

    #[Test]
    public function placement_links_to_block_and_orders(): void
    {
        $block = Block::factory()->picture()->withId('01J000000000000000000BBBBB')->create([
            'owner_container_type' => 'news',
            'owner_container_id' => '42',
        ]);

        $placement = BlockPlacement::forceCreate([
            'container_type' => 'news',
            'container_id' => '42',
            'slot' => 'content_builder_value',
            'rank' => '000000',
            'block_id' => $block->id,
        ]);

        $this->assertTrue($placement->block->is($block));
    }
}
