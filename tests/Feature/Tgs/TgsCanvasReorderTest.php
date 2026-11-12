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

class TgsCanvasReorderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reordering_root_blocks_applies_the_new_order(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        // 'column' (a container) commits+places immediately (ADR-020); a leaf like 'content'
        // would only draft until the edit modal is submitted, leaving no BlockPlacement.
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('addRootBlock', 'column')
            ->call('addRootBlock', 'column')
            ->call('addRootBlock', 'column')
            ->assertSuccessful();

        $placements = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->orderBy('rank')
            ->pluck('block_id')
            ->all();

        $this->assertCount(3, $placements);
        [$id1, $id2, $id3] = $placements;

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('reorderRoot', [$id3, $id1, $id2])
            ->assertSuccessful();

        $order = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->orderBy('rank')
            ->pluck('block_id')
            ->all();

        $this->assertSame([$id3, $id1, $id2], $order);
    }

    #[Test]
    public function reordering_child_blocks_applies_the_new_order(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        $parentBlock = Block::factory()->column()->withId('parent-col-reorder')->create([
            'owner_container_type' => $host->containerType,
            'owner_container_id' => (string) $demo->id,
        ]);

        BlockPlacement::forceCreate([
            'container_type' => $host->containerType,
            'container_id' => $host->containerId,
            'slot' => $host->slot,
            'rank' => '000000',
            'block_id' => $parentBlock->id,
        ]);

        // 'column' (a container) commits+places immediately (ADR-020); a leaf like 'content'
        // would only draft until the edit modal is submitted, leaving no BlockPlacement.
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('addChildBlock', $parentBlock->id, 'content', 'column')
            ->call('addChildBlock', $parentBlock->id, 'content', 'column')
            ->call('addChildBlock', $parentBlock->id, 'content', 'column')
            ->assertSuccessful();

        $placements = BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $parentBlock->id)
            ->where('slot', 'content')
            ->orderBy('rank')
            ->pluck('block_id')
            ->all();

        $this->assertCount(3, $placements);
        [$id1, $id2, $id3] = $placements;

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('reorderChild', $parentBlock->id, 'content', [$id3, $id1, $id2])
            ->assertSuccessful();

        $order = BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $parentBlock->id)
            ->where('slot', 'content')
            ->orderBy('rank')
            ->pluck('block_id')
            ->all();

        $this->assertSame([$id3, $id1, $id2], $order);
    }

    #[Test]
    public function reordering_root_blocks_works_when_a_block_is_referenced_twice_in_the_same_slot(): void
    {
        // Reproduces a live bug (same class as deleteBlock()'s, see 66b5d4a): a block
        // referenced twice in the same slot (e.g. via insertFromToken's "as reference"
        // option) leaves two BlockPlacement rows sharing one block_id. reorderRoot() used
        // to call moveWithinSlot() once per position, which identified "the" placement to
        // move by block_id alone — ambiguous and unsafe once a block_id can occupy more
        // than one position in a slot.
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('addRootBlock', 'column')
            ->call('addRootBlock', 'column')
            ->assertSuccessful();

        $placements = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->orderBy('rank')
            ->pluck('block_id')
            ->all();
        [$a, $b] = $placements;

        // Second placement of the SAME block A, in the SAME slot (mirrors "insert by
        // reference" back into the slot the source already occupies).
        BlockPlacement::forceCreate([
            'container_type' => $host->containerType,
            'container_id' => $host->containerId,
            'slot' => $host->slot,
            'rank' => '000002',
            'block_id' => $a,
        ]);

        // New order: [B, A(first), A(second)] — B moves to the front.
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('reorderRoot', [$b, $a, $a])
            ->assertSuccessful();

        $order = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->orderBy('rank')
            ->pluck('block_id')
            ->all();
        $this->assertSame([$b, $a, $a], $order);
    }
}
