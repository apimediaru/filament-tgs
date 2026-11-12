<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\BlockTreeAssembler;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class BlockTreeAssemblerTest extends TestCase
{
    use RefreshDatabase;

    private HostDescriptor $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new HostDescriptor('news', '42', 'content_builder_value');
    }

    #[Test]
    public function empty_host_returns_empty_array(): void
    {
        $this->assertSame([], app(BlockTreeAssembler::class)->assemble($this->host));
    }

    #[Test]
    public function round_trips_a_nested_tree_through_persist_and_assemble(): void
    {
        $tree = [
            ['type' => 'picture', 'data' => ['id' => 'PIC', 'uuid' => 'm1']],
            ['type' => 'accordion', 'data' => ['id' => 'ACC', 'is_accordion' => true, 'items' => [
                ['title' => 'T1', 'is_open' => false, 'content' => [
                    ['type' => 'content', 'data' => ['id' => 'A1', 'body' => 'one']],
                ]],
            ]]],
        ];

        app(CanvasPersistence::class)->replaceTree($this->host, $tree);
        $assembled = app(BlockTreeAssembler::class)->assemble($this->host);

        $this->assertEquals($tree, $assembled);
    }

    #[Test]
    public function a_soft_deleted_root_block_is_excluded(): void
    {
        Block::factory()->content(['body' => 'gone'])->withId('R1')->create([
            'owner_container_type' => 'news', 'owner_container_id' => '42',
            'deleted_at' => now(), 'deleted_op_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'news', 'container_id' => '42', 'slot' => 'content_builder_value',
            'rank' => '000000', 'block_id' => 'R1',
        ]);

        $this->assertSame([], app(BlockTreeAssembler::class)->assemble($this->host));
    }

    #[Test]
    public function a_soft_deleted_container_hides_its_still_placed_children_too(): void
    {
        Block::factory()->column()->withId('COL')->create([
            'owner_container_type' => 'news', 'owner_container_id' => '42',
            'deleted_at' => now(), 'deleted_op_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ]);
        Block::factory()->content(['body' => 'still linked'])->withId('CH')->create([
            'owner_container_type' => 'news', 'owner_container_id' => '42',
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'news', 'container_id' => '42', 'slot' => 'content_builder_value',
            'rank' => '000000', 'block_id' => 'COL',
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'block', 'container_id' => 'COL', 'slot' => 'content',
            'rank' => '000000', 'block_id' => 'CH',
        ]);

        $this->assertSame([], app(BlockTreeAssembler::class)->assemble($this->host));
    }

    #[Test]
    public function root_blocks_are_assembled_in_rank_order_not_creation_or_id_order(): void
    {
        // B1 is created and placed first, and sorts first alphabetically — but its rank is
        // the later one. If assemble() sorted by creation order/id instead of rank, this
        // would come back [B1, M1].
        Block::factory()->content(['body' => 'b'])->withId('B1')->create([
            'owner_container_type' => 'news', 'owner_container_id' => '42']);
        BlockPlacement::forceCreate(['container_type' => 'news', 'container_id' => '42',
            'slot' => 'content_builder_value', 'rank' => '000005', 'block_id' => 'B1']);
        Block::factory()->content(['body' => 'm'])->withId('M1')->create([
            'owner_container_type' => 'news', 'owner_container_id' => '42']);
        BlockPlacement::forceCreate(['container_type' => 'news', 'container_id' => '42',
            'slot' => 'content_builder_value', 'rank' => '000001', 'block_id' => 'M1']);

        $assembled = app(BlockTreeAssembler::class)->assemble($this->host);

        $this->assertSame(['M1', 'B1'], array_column(array_column($assembled, 'data'), 'id'));
    }

    #[Test]
    public function nested_children_within_a_slot_are_assembled_in_rank_order_not_creation_or_id_order(): void
    {
        Block::factory()->column()->withId('COL')->create([
            'owner_container_type' => 'news', 'owner_container_id' => '42']);
        BlockPlacement::forceCreate(['container_type' => 'news', 'container_id' => '42',
            'slot' => 'content_builder_value', 'rank' => '000000', 'block_id' => 'COL']);

        // Same honest setup as the root-level test, one level down: CH_B is created and
        // placed first but ranked later within COL's "content" slot.
        Block::factory()->content(['body' => 'b'])->withId('CH_B')->create([
            'owner_container_type' => 'news', 'owner_container_id' => '42']);
        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => 'COL',
            'slot' => 'content', 'rank' => '000005', 'block_id' => 'CH_B']);
        Block::factory()->content(['body' => 'm'])->withId('CH_M')->create([
            'owner_container_type' => 'news', 'owner_container_id' => '42']);
        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => 'COL',
            'slot' => 'content', 'rank' => '000001', 'block_id' => 'CH_M']);

        $assembled = app(BlockTreeAssembler::class)->assemble($this->host);

        $children = $assembled[0]['data']['content'];
        $this->assertSame(['CH_M', 'CH_B'], array_column(array_column($children, 'data'), 'id'));
    }

    #[Test]
    public function shared_subtree_is_rendered_at_root_and_inside_another_container(): void
    {
        $persistence = app(CanvasPersistence::class);
        $persistence->replaceTree($this->host, [
            ['type' => 'column', 'data' => ['id' => 'SHARED', 'content' => [
                ['type' => 'content', 'data' => ['id' => 'LEAF', 'content' => '<p>Shared</p>']],
            ]]],
            ['type' => 'column', 'data' => ['id' => 'OTHER', 'content' => []]],
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'block', 'container_id' => 'OTHER', 'slot' => 'content',
            'rank' => '000000', 'block_id' => 'SHARED',
        ]);

        $tree = app(BlockTreeAssembler::class)->assemble($this->host);

        $this->assertSame($tree[0], $tree[1]['data']['content'][0] ?? null);
        $this->assertSame('LEAF', $tree[1]['data']['content'][0]['data']['content'][0]['data']['id']);
    }

    #[Test]
    public function assemble_block_preserves_shared_subtrees_and_cuts_only_ancestor_cycles(): void
    {
        app(CanvasPersistence::class)->replaceTree($this->host, [
            ['type' => 'column', 'data' => ['id' => 'ROOT', 'content' => [
                ['type' => 'column', 'data' => ['id' => 'SHARED', 'content' => [
                    ['type' => 'content', 'data' => ['id' => 'LEAF', 'content' => '<p>Shared</p>']],
                ]]],
                ['type' => 'column', 'data' => ['id' => 'OTHER', 'content' => []]],
            ]]],
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'block', 'container_id' => 'OTHER', 'slot' => 'content',
            'rank' => '000000', 'block_id' => 'SHARED',
        ]);
        // A genuine back edge must terminate in both occurrences of SHARED.
        BlockPlacement::forceCreate([
            'container_type' => 'block', 'container_id' => 'SHARED', 'slot' => 'content',
            'rank' => 'zzzzzz', 'block_id' => 'ROOT',
        ]);

        $tree = app(BlockTreeAssembler::class)->assembleBlock('ROOT');
        $children = $tree['data']['content'];
        $this->assertSame($children[0], $children[1]['data']['content'][0] ?? null);
        $this->assertCount(1, $children[0]['data']['content']);
        $this->assertSame('LEAF', $children[0]['data']['content'][0]['data']['id']);
    }

    #[Test]
    public function skips_a_cycle_without_infinite_loop(): void
    {
        Block::factory()->column()->withId('X')->create([
            'owner_container_type' => 'news',
            'owner_container_id' => '42',
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'news', 'container_id' => '42', 'slot' => 'content_builder_value',
            'rank' => '000000', 'block_id' => 'X',
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'block', 'container_id' => 'X', 'slot' => 'content',
            'rank' => '000000', 'block_id' => 'X',
        ]);

        $assembled = app(BlockTreeAssembler::class)->assemble($this->host);

        $this->assertCount(1, $assembled);
    }
}
