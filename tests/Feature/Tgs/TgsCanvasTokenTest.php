<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasTokenTest extends TestCase
{
    use RefreshDatabase;

    private function orphanBlock(string $id, string $type = 'content'): Block
    {
        return Block::create([
            'id' => $id, 'type' => $type, 'data' => ['content' => 'token source'],
            'owner_container_type' => 'orphan', 'owner_container_id' => 'unknown',
        ]);
    }

    #[Test]
    public function insert_from_token_as_copy_clones_the_block(): void
    {
        $this->orphanBlock('TOKEN-COPY-SRC');
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->call('insertFromToken', 'TOKEN-COPY-SRC', true);

        $placements = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->get();

        $this->assertCount(1, $placements);
        $this->assertNotSame('TOKEN-COPY-SRC', $placements->first()->block_id);

        $clone = Block::find($placements->first()->block_id);
        $this->assertSame($host->containerType, $clone->owner_container_type);
        $this->assertSame($host->containerId, $clone->owner_container_id);
    }

    #[Test]
    public function insert_from_token_as_reference_shares_the_same_block_id(): void
    {
        $this->orphanBlock('TOKEN-REF-SRC');
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->call('insertFromToken', 'TOKEN-REF-SRC', false);

        $this->assertTrue(BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->where('block_id', 'TOKEN-REF-SRC')
            ->exists());
    }

    #[Test]
    public function insert_from_token_with_an_unknown_id_is_a_no_op(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->call('insertFromToken', 'TOTALLY-UNKNOWN-ID', true);

        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->count());
    }

    #[Test]
    public function insert_from_token_refuses_a_type_not_allowed_in_the_current_picker_target(): void
    {
        // 'column' has an allowedChildren restriction elsewhere in this suite (accordion's
        // 'items' slot only accepts 'accordion_item') — reuse that exact fixture shape: a
        // 'column' block's own single 'content' slot has NO restriction, so instead target a
        // slot that DOES restrict types. Simplest reliable case: root-level insertion has no
        // restriction at all, so exercise the restriction via a parent whose slot only accepts
        // a narrower type, mirroring TgsCanvasLibraryTest's own "refuses a type not allowed"
        // test fixture shape (see that file for the established pattern if this needs
        // adjusting — the key assertion is just that a disallowed type is a no-op, not which
        // specific block type is used to prove it).
        $this->orphanBlock('TOKEN-BAD-TYPE', 'double_column');
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $parent = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'accordion', []);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker', $parent->id, 'items')
            ->call('insertFromToken', 'TOKEN-BAD-TYPE', true);

        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $parent->id)
            ->where('slot', 'items')
            ->count());
    }

    #[Test]
    public function insert_from_token_refuses_a_cyclic_reference(): void
    {
        $this->orphanBlock('TOKEN-CYCLE-ROOT', 'column');
        $child = $this->orphanBlock('TOKEN-CYCLE-CHILD');
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        app(CanvasPersistence::class)->copyBlockInto($host, $host->containerType, $host->containerId, $host->slot, 'TOKEN-CYCLE-ROOT');
        $rootPlacement = BlockPlacement::query()->where('container_type', $host->containerType)->where('container_id', $host->containerId)->first();
        $rootCloneId = $rootPlacement->block_id;

        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => $rootCloneId, 'slot' => 'content', 'rank' => '000000', 'block_id' => $child->id]);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker', $rootCloneId, 'content')
            ->call('insertFromToken', $rootCloneId, false);

        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $rootCloneId)
            ->where('block_id', $rootCloneId)
            ->count());
    }
}
