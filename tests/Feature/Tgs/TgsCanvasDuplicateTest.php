<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasDuplicateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function duplicating_a_root_block_inserts_a_copy_with_the_same_type_and_data(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->content(['content' => 'Оригинал'])->withId('ORIG-ROOT')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => (string) $demo->id,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'ORIG-ROOT']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('duplicateRootBlock', 'ORIG-ROOT')
            ->assertSuccessful();

        $placements = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->orderBy('rank')
            ->get();

        $this->assertCount(2, $placements);

        $copyBlockId = $placements->last()->block_id;
        $this->assertNotSame('ORIG-ROOT', $copyBlockId);

        $copy = Block::find($copyBlockId);
        $this->assertNotNull($copy);
        $this->assertSame('content', $copy->type);
        $this->assertSame(['content' => 'Оригинал'], $copy->data);
    }

    #[Test]
    public function duplicating_a_child_block_inserts_a_copy_in_the_same_slot(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();
        $own = ['owner_container_type' => $host->containerType, 'owner_container_id' => (string) $demo->id];

        Block::factory()->column()->withId('parent-col')->create($own);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'parent-col']);

        Block::factory()->content(['content' => 'Дочерний'])->withId('ORIG-CHILD')->create($own);
        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => 'parent-col',
            'slot' => 'content', 'rank' => '000000', 'block_id' => 'ORIG-CHILD']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('duplicateChildBlock', 'parent-col', 'content', 'ORIG-CHILD')
            ->assertSuccessful();

        $placements = BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', 'parent-col')
            ->where('slot', 'content')
            ->orderBy('rank')
            ->get();

        $this->assertCount(2, $placements);

        $copyBlockId = $placements->last()->block_id;
        $this->assertNotSame('ORIG-CHILD', $copyBlockId);

        $copy = Block::find($copyBlockId);
        $this->assertNotNull($copy);
        $this->assertSame('content', $copy->type);
        $this->assertSame(['content' => 'Дочерний'], $copy->data);
    }

    #[Test]
    public function duplicating_a_missing_block_is_a_no_op(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('duplicateRootBlock', 'does-not-exist')
            ->assertSuccessful();

        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->count());
    }

    #[Test]
    public function duplicating_a_root_container_deep_copies_its_children_and_inserts_right_after_it(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();
        $own = ['owner_container_type' => $host->containerType, 'owner_container_id' => (string) $demo->id];

        Block::factory()->column()->withId('ORIG-COL')->create($own);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'ORIG-COL']);

        Block::factory()->content(['content' => 'Дочерний'])->withId('ORIG-CHILD')->create($own);
        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => 'ORIG-COL',
            'slot' => 'content', 'rank' => '000000', 'block_id' => 'ORIG-CHILD']);

        // Second root block that must stay after the inserted copy, to prove the copy lands
        // right after the source rather than at the end of the slot.
        Block::factory()->content(['content' => 'Хвост'])->withId('ORIG-TAIL')->create($own);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000001', 'block_id' => 'ORIG-TAIL']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('duplicateRootBlock', 'ORIG-COL')
            ->assertSuccessful();

        $rootPlacements = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->orderBy('rank')
            ->get();

        $this->assertCount(3, $rootPlacements);
        $this->assertSame('ORIG-COL', $rootPlacements[0]->block_id);
        $copyColumnId = $rootPlacements[1]->block_id;
        $this->assertNotSame('ORIG-COL', $copyColumnId);
        $this->assertSame('ORIG-TAIL', $rootPlacements[2]->block_id);

        $copyChildPlacements = BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $copyColumnId)
            ->where('slot', 'content')
            ->get();

        $this->assertCount(1, $copyChildPlacements);
        $copyChildId = $copyChildPlacements->first()->block_id;
        $this->assertNotSame('ORIG-CHILD', $copyChildId);

        $copyChild = Block::find($copyChildId);
        $this->assertNotNull($copyChild);
        $this->assertSame('content', $copyChild->type);
        $this->assertSame(['content' => 'Дочерний'], $copyChild->data);
    }

    #[Test]
    public function duplicating_a_locked_root_block_is_a_no_op(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->content(['content' => 'Оригинал'])->withId('LOCKED-ROOT')->locked()->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => (string) $demo->id,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'LOCKED-ROOT']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('duplicateRootBlock', 'LOCKED-ROOT')
            ->assertSuccessful();

        $this->assertSame(1, BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->count());
    }
}
