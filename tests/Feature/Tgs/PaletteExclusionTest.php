<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class PaletteExclusionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function canvas_root_palette_excludes_child_only_blocks_but_keeps_containers(): void
    {
        $demo = TgsDemo::factory()->create();

        /** @var TgsCanvas $canvas */
        $canvas = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->instance();

        $rootTypes = array_column($canvas->availableBlocksFor(null, null), 'type');

        $this->assertNotContains('tab_item', $rootTypes);
        $this->assertContains('tabs', $rootTypes);
    }

    #[Test]
    public function canvas_items_slot_offers_the_item_block(): void
    {
        $demo = TgsDemo::factory()->create();

        /** @var TgsCanvas $canvas */
        $canvas = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->instance();

        $slotTypes = array_column($canvas->availableBlocksFor('tabs', 'items'), 'type');

        $this->assertSame(['tab_item'], $slotTypes);
    }

    #[Test]
    public function canvas_unrestricted_slot_excludes_child_only_blocks(): void
    {
        $demo = TgsDemo::factory()->create();

        /** @var TgsCanvas $canvas */
        $canvas = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->instance();

        // 'column' declares a 'content' slot but no allowedChildren restriction
        // (BlockChildPolicy::allowedChildrenFor() returns [] = unrestricted). Child-only
        // blocks like tab_item/accordion_item must still be excluded there.
        $slotTypes = array_column($canvas->availableBlocksFor('column', 'content'), 'type');

        $this->assertNotContains('tab_item', $slotTypes);
        $this->assertNotContains('accordion_item', $slotTypes);
        $this->assertContains('content', $slotTypes);
    }
}
