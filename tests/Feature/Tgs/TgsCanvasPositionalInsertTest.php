<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasPositionalInsertTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function open_picker_stores_the_requested_position_and_the_next_open_resets_it(): void
    {
        $group = $this->makeGroup();

        // closePicker() no longer resets pickerParentId/pickerSlot/pickerPosition itself
        // (§2b UX follow-up, 2026-07-22): Filament's native slideOver Cancel button closes the
        // action directly, bypassing our closePicker() wrapper entirely, so any reset placed
        // there would never run on a plain cancel. The reset instead lives in openPicker()
        // (always runs fresh on every open, regardless of how the previous one was closed) —
        // see TgsCanvas::openPicker()'s docblock.
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('openPicker', null, null, 1)
            ->assertSet('pickerPosition', 1)
            ->call('closePicker');

        $this->assertSame([], $component->get('mountedActions'));

        $component->call('openPicker')->assertSet('pickerPosition', null);
    }

    #[Test]
    public function choosing_a_block_at_a_root_position_inserts_it_there_and_shifts_siblings(): void
    {
        $group = $this->makeGroup();
        $host = $group->hostDescriptor();

        // 'column' (a container) commits+places immediately (ADR-020); a leaf like 'content'
        // would only draft until the edit modal is submitted, leaving no BlockPlacement.
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('addRootBlock', 'column')
            ->call('addRootBlock', 'column')
            ->call('addRootBlock', 'column');

        $before = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->orderBy('rank')
            ->pluck('block_id')
            ->all();

        $this->assertCount(3, $before);

        // 'double_column' (also a container, so it commits immediately) is used here purely
        // because it's distinguishable by type from the 'column' setup blocks above.
        $component
            ->call('openPicker', null, null, 1)
            ->call('chooseBlock', 'double_column')
            ->assertSuccessful();

        $placements = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->orderBy('rank')
            ->get();

        $this->assertCount(4, $placements);

        $newBlockId = Block::query()->where('type', 'double_column')->value('id');

        $this->assertSame($before[0], $placements[0]->block_id);
        $this->assertSame($newBlockId, $placements[1]->block_id);
        $this->assertSame($before[1], $placements[2]->block_id);
        $this->assertSame($before[2], $placements[3]->block_id);
    }

    #[Test]
    public function choosing_a_block_at_a_nested_slot_position_inserts_it_there(): void
    {
        $group = $this->makeGroup();
        $host = $group->hostDescriptor();

        $parentBlock = Block::factory()->column()->withId('parent-col')->create([
            'owner_container_type' => $host->containerType,
            'owner_container_id' => $host->containerId,
        ]);

        BlockPlacement::forceCreate([
            'container_type' => $host->containerType,
            'container_id' => $host->containerId,
            'slot' => $host->slot,
            'rank' => '000000',
            'block_id' => $parentBlock->id,
        ]);

        $child1 = Block::factory()->content()->withId('child-1')->create([
            'owner_container_type' => $host->containerType,
            'owner_container_id' => $host->containerId,
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'block',
            'container_id' => $parentBlock->id,
            'slot' => 'left',
            'rank' => '000000',
            'block_id' => $child1->id,
        ]);

        $child2 = Block::factory()->content()->withId('child-2')->create([
            'owner_container_type' => $host->containerType,
            'owner_container_id' => $host->containerId,
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'block',
            'container_id' => $parentBlock->id,
            'slot' => 'left',
            'rank' => '000001',
            'block_id' => $child2->id,
        ]);

        // 'column' (a container) commits+places immediately (ADR-020); child1/child2 above are
        // plain DB rows created directly, so 'content' staying their type doesn't matter here.
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('openPicker', $parentBlock->id, 'left', 1)
            ->assertSet('pickerPosition', 1)
            ->call('chooseBlock', 'column')
            ->assertSuccessful();

        $placements = BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $parentBlock->id)
            ->where('slot', 'left')
            ->orderBy('rank')
            ->get();

        $this->assertCount(3, $placements);
        $this->assertSame($child1->id, $placements[0]->block_id);
        $this->assertSame($child2->id, $placements[2]->block_id);

        $newChildId = $placements[1]->block_id;
        $this->assertNotSame($child1->id, $newChildId);
        $this->assertNotSame($child2->id, $newChildId);
    }

    #[Test]
    public function choosing_a_block_without_an_open_position_still_appends_to_the_end(): void
    {
        $group = $this->makeGroup();
        $host = $group->hostDescriptor();

        // 'column' (a container) commits+places immediately (ADR-020); a leaf like 'content'
        // would only draft until the edit modal is submitted, leaving no BlockPlacement.
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('addRootBlock', 'column')
            ->call('addRootBlock', 'column');

        $before = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->orderBy('rank')
            ->pluck('block_id')
            ->all();

        // 'double_column' (also a container) is used here purely to be distinguishable by
        // type from the 'column' setup blocks above.
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('openPicker')
            ->assertSet('pickerPosition', null)
            ->call('chooseBlock', 'double_column')
            ->assertSuccessful();

        $placements = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->orderBy('rank')
            ->get();

        $this->assertCount(3, $placements);
        $this->assertSame($before[0], $placements[0]->block_id);
        $this->assertSame($before[1], $placements[1]->block_id);

        $newBlockId = Block::query()->where('type', 'double_column')->value('id');
        $this->assertSame($newBlockId, $placements[2]->block_id);
    }

    private function makeGroup(): Canvas
    {
        $demo = TgsDemo::factory()->create();

        return $demo->canvas('content');
    }
}
