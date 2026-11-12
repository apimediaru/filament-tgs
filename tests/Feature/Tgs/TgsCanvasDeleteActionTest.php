<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasDeleteActionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_delete_action_removes_a_root_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', [],
        );

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->callAction('deleteBlockAction', arguments: ['block' => $block->id, 'parent' => null, 'slot' => null])
            ->assertSuccessful();

        $this->assertNotNull(Block::find($block->id)?->deleted_at, 'block must be soft-deleted, not gone');
    }

    #[Test]
    public function the_delete_action_removes_a_child_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);
        $parent = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $child = $p->addBlock($host, 'block', $parent->id, 'content', 'content', []);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->callAction('deleteBlockAction', arguments: ['block' => $child->id, 'parent' => $parent->id, 'slot' => 'content'])
            ->assertSuccessful();

        $this->assertNotNull(Block::find($child->id)?->deleted_at, 'block must be soft-deleted, not gone');
    }
}
