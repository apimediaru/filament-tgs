<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Blocks\TabItem;
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

class SingleChildSlotTest extends TestCase
{
    use RefreshDatabase;

    private function tabsOnCanvas(TgsDemo $demo): Block
    {
        $host = HostDescriptor::forModelSlot($demo, 'content');

        return app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'tabs', [],
        );
    }

    #[Test]
    public function items_slot_reports_its_single_child_type(): void
    {
        $demo = TgsDemo::factory()->create();

        /** @var TgsCanvas $canvas */
        $canvas = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->instance();

        $this->assertSame('tab_item', $canvas->slotSingleChild('tabs', 'items'));
        $this->assertNull($canvas->slotSingleChild(null, null)); // корень — не одиночный
    }

    #[Test]
    public function quick_add_creates_the_item_and_opens_its_editor(): void
    {
        $demo = TgsDemo::factory()->create();
        $tabs = $this->tabsOnCanvas($demo);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('quickAddChild', $tabs->id, 'items')
            ->assertActionMounted('editBlockAction');

        // quickAddChild always drafts (ADR-020) — commit through the already-mounted modal
        // (which already carries the placement target) before asserting placement exists.
        $component->fillForm(['title' => 'Вкладка'])->callMountedAction();

        $this->assertTrue(
            BlockPlacement::query()->where('container_id', $tabs->id)->where('slot', 'items')
                ->whereIn('block_id', Block::query()->where('type', 'tab_item')->pluck('id'))
                ->exists(),
        );
    }

    #[Test]
    public function tab_item_declares_an_add_label(): void
    {
        $this->assertNotNull(TabItem::make()->blockAddLabel());
    }
}
