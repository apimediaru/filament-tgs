<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Bug repro (2026-07-26): rapid repeated clicks on "Добавить блок" (root, insert-gap, slot
 * quick-add) can fire multiple overlapping Livewire requests — nothing on the client stops a
 * second click before the first request resolves. Every add-block trigger must disable itself
 * (wire:loading.attr) and stay disabled only for its own request (wire:target scoped to the
 * method it calls), so a user can't fan out concurrent addRootBlock()/addChildBlock()/
 * quickAddChild() calls that race on rank/position.
 */
class TgsCanvasAddButtonLoadingGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function root_add_block_button_disables_itself_while_its_own_request_is_in_flight(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertSeeHtml('wire:click="openPicker"')
            ->assertSeeHtml(['wire:loading.attr="disabled"', 'wire:target="openPicker"']);
    }

    #[Test]
    public function insert_gap_hover_button_disables_itself_while_its_own_request_is_in_flight(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = HostDescriptor::forModelSlot($demo, 'content');
        app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'Текст']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertSeeHtml('wire:click="openPicker(null, null, 0)"')
            ->assertSeeHtml(['wire:loading.attr="disabled"', 'wire:target="openPicker"']);
    }

    #[Test]
    public function picker_drawer_type_tiles_disable_themselves_while_their_own_request_is_in_flight(): void
    {
        // The picker drawer's tile buttons live in insertBlockAction()'s ->modalContent()
        // view — Filament renders that body lazily behind `wire:partial`, which the
        // Livewire test harness's ->html() does not resurface even after ->mountAction().
        // Render the same partial directly with the same variables addRootBlockAction()
        // passes it, matching the view/data contract in TgsCanvas::insertBlockAction().
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        /** @var TgsCanvas $component */
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->instance();

        $html = view('tgs::_insert-picker', [
            'pickerTab' => 'new',
            'pickerBlocks' => $component->getPickerBlocksProperty(),
            'pickerIsFiltered' => $component->getPickerIsFilteredProperty(),
            'libraryBlocks' => $component->getLibraryBlocksProperty(),
            'collections' => $component->getCollectionsProperty(),
            'isLibraryHost' => false,
        ])->render();

        $this->assertStringContainsString("wire:click=\"chooseBlock('code')\"", $html);
        $this->assertStringContainsString('wire:loading.attr="disabled"', $html);
        $this->assertStringContainsString('wire:target="chooseBlock"', $html);
    }

    #[Test]
    public function empty_slot_add_button_disables_itself_while_its_own_request_is_in_flight(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = HostDescriptor::forModelSlot($demo, 'content');
        // 'column' declares a 'content' slot with no children yet — renders the empty-slot
        // "+ Добавить блок" button branch in _node.blade.php.
        app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertSeeHtml(['wire:loading.attr="disabled"', 'wire:target="quickAddChild,openPicker"']);
    }

    #[Test]
    public function single_child_slot_quick_add_button_targets_quick_add_child(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = HostDescriptor::forModelSlot($demo, 'content');
        // 'tabs' → slot 'items' allows only 'tab_item' (single-child slot, Task A quick-add).
        $tabs = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'tabs', []);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertSeeHtml("wire:click=\"quickAddChild('{$tabs->id}', 'items')\"")
            ->assertSeeHtml(['wire:loading.attr="disabled"', 'wire:target="quickAddChild,openPicker"']);
    }
}
