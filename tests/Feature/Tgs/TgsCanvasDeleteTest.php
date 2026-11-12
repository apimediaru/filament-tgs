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

class TgsCanvasDeleteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deleting_a_root_block_removes_it(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        // Add a block ('column' is a container — commits+places immediately, ADR-020;
        // a leaf would only draft until the edit modal is submitted).
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('addRootBlock', 'column')
            ->assertSuccessful();

        // Get the block ID from placements
        $placement = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->first();

        $this->assertNotNull($placement);
        $blockId = $placement->block_id;

        // Delete the block
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('deleteRootBlock', $blockId)
            ->assertSuccessful();

        // Assert the block placement is gone
        $this->assertDatabaseMissing('tgs_block_placements', [
            'container_type' => $host->containerType,
            'container_id' => $host->containerId,
            'slot' => $host->slot,
            'block_id' => $blockId,
        ]);
    }

    #[Test]
    public function deleting_a_child_block_removes_it(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        // Create a parent block with a 'content' slot (column type)
        $parentBlock = Block::factory()->column()->withId('parent-col')->create([
            'owner_container_type' => $host->containerType,
            'owner_container_id' => (string) $demo->id,
        ]);

        // Add the parent block as a root placement
        BlockPlacement::forceCreate([
            'container_type' => $host->containerType,
            'container_id' => $host->containerId,
            'slot' => $host->slot,
            'rank' => '000000',
            'block_id' => $parentBlock->id,
        ]);

        // Add a child block (container type — commits+places immediately, ADR-020) to the
        // parent's 'content' slot.
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('addChildBlock', $parentBlock->id, 'content', 'column')
            ->assertSuccessful();

        // Get the child block ID
        $childPlacement = BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $parentBlock->id)
            ->where('slot', 'content')
            ->first();

        $this->assertNotNull($childPlacement);
        $childBlockId = $childPlacement->block_id;

        // Delete the child block
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('deleteChildBlock', $parentBlock->id, 'content', $childBlockId)
            ->assertSuccessful();

        // Assert the child block placement is gone
        $this->assertDatabaseMissing('tgs_block_placements', [
            'container_type' => 'block',
            'container_id' => $parentBlock->id,
            'slot' => 'content',
            'block_id' => $childBlockId,
        ]);
    }
}
