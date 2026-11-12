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

class TgsCanvasAddTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function adding_a_root_block_persists_it_owned_by_the_host(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        // 'column' (a container, blockSlots() non-empty) commits+places immediately (ADR-020) —
        // unlike a leaf, which would only draft until the edit modal is submitted.
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('addRootBlock', 'column')
            ->assertSuccessful();

        $this->assertSame(1, BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->count());
    }

    #[Test]
    public function adding_a_child_block_persists_it_under_the_parent_in_the_named_slot(): void
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

        // Add a child block (a container type, so it commits+places immediately per ADR-020)
        // to the parent's 'content' slot.
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('addChildBlock', $parentBlock->id, 'content', 'column')
            ->assertSuccessful();

        // Assert the child block placement exists
        $this->assertSame(1, BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $parentBlock->id)
            ->where('slot', 'content')
            ->count());
    }
}
