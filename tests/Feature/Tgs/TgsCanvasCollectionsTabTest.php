<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Collection;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Task 4 (block collections UI): render-assert coverage for the drawer's "Collections" tab
 * and the block-selection modal — the underlying server logic (getCollectionsProperty(),
 * collectionBlocks(), insertFromCollection()) is already unit-tested in Task 3
 * (TgsCanvasCollectionTest). This file only proves the UI/Action wiring actually works.
 *
 * Modal content (schema fields, translated heading) is not embedded in the Livewire test
 * component's ->html() output for Filament actions in this codebase (confirmed against the
 * existing addToLibraryAction, whose modal fields don't appear in ->html() either) — so
 * modal-side assertions here go through the mounted Action instance directly
 * (getAction()->getModalHeading()) or assertActionDataSet(), mirroring how
 * TgsCanvasLibraryTest already asserts action outcomes without relying on modal HTML.
 */
class TgsCanvasCollectionsTabTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> ids of the 2 blocks added to the collection's 'blocks' group */
    private function collectionWithTwoBlocks(Collection $collection): array
    {
        $host = $collection->canvas('blocks')->hostDescriptor();
        $persistence = app(CanvasPersistence::class);

        $ids = [];
        foreach (['first', 'second'] as $text) {
            $block = $persistence->addBlock(
                $host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => $text],
            );
            $ids[] = $block->id;
        }

        return $ids;
    }

    #[Test]
    public function drawer_shows_the_collections_tab_and_lists_the_collection_by_name(): void
    {
        $collection = Collection::factory()->create(['name' => 'Промо-набор']);
        $this->collectionWithTwoBlocks($collection);

        $demo = TgsDemo::factory()->create();

        // Modal content (insertBlockAction's ->modalContent()) isn't reliably present in this
        // harness's ->html() output — Livewire::test() reuses ONE PHP object across chained
        // calls, but InteractsWithActions::$hasActionsModalRendered is a once-per-render guard
        // meant for a fresh object per request (see TgsBlockHistoryDrawer's own UI test for the
        // same limitation). Render the extracted _insert-picker.blade.php partial directly with
        // the same view data the action would pass, bypassing the Action/modal machinery.
        $canvas = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id]);

        $html = view('tgs::_insert-picker', [
            'pickerTab' => 'collections',
            'pickerBlocks' => [],
            'pickerIsFiltered' => false,
            'libraryBlocks' => [],
            'collections' => $canvas->instance()->getCollectionsProperty(), // @phpstan-ignore method.notFound
            'isLibraryHost' => false,
        ])->render();

        $this->assertStringContainsString(__('tgs::filament.tgs.canvas.collections.tab_collections'), $html);
        $this->assertStringContainsString('Промо-набор', $html);
    }

    #[Test]
    public function insert_from_collection_action_modal_heading_is_the_collection_name(): void
    {
        $collection = Collection::factory()->create(['name' => 'Промо-набор']);
        $this->collectionWithTwoBlocks($collection);

        $demo = TgsDemo::factory()->create();

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('insertFromCollectionAction', ['collection' => $collection->id]);

        // @phpstan-ignore method.notFound (Livewire component method, ->instance() is typed as the base Component)
        $action = $component->instance()->getAction('insertFromCollectionAction');

        $this->assertSame('Промо-набор', $action->getModalHeading());
    }

    #[Test]
    public function insert_from_collection_action_modal_defaults_to_all_blocks_selected(): void
    {
        $collection = Collection::factory()->create(['name' => 'Промо-набор']);
        $ids = $this->collectionWithTwoBlocks($collection);

        $demo = TgsDemo::factory()->create();

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('insertFromCollectionAction', ['collection' => $collection->id])
            ->assertSchemaStateSet(['blocks' => $ids]);
    }

    #[Test]
    public function insert_from_collection_action_modal_falls_back_to_generic_heading_for_a_vanished_collection(): void
    {
        $demo = TgsDemo::factory()->create();

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('insertFromCollectionAction', ['collection' => 999999]);

        // @phpstan-ignore method.notFound (Livewire component method, ->instance() is typed as the base Component)
        $action = $component->instance()->getAction('insertFromCollectionAction');

        $this->assertSame(__('tgs::filament.tgs.canvas.collections.tab_collections'), $action->getModalHeading());
    }

    #[Test]
    public function submitting_the_action_inserts_copies_of_only_the_selected_blocks(): void
    {
        $collection = Collection::factory()->create(['name' => 'Промо-набор']);
        [$first, $second] = $this->collectionWithTwoBlocks($collection);

        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->mountAction('insertFromCollectionAction', ['collection' => $collection->id])
            // NB: setActionData()/fillForm() dot-flattens the array and only overwrites
            // matching indices, leaving stale elements from the (larger) default selection
            // behind for a shrinking array — a known Filament testing-helper quirk with
            // array-valued fields, not a production bug (a real checkbox submission always
            // sends the full checked set). Target the mounted action's data property
            // directly to get a clean full replacement.
            // Index 1, not 0: openPicker() above mounts insertBlockAction first (index 0);
            // insertFromCollectionAction resolves as a NESTED action on top of it (index 1) —
            // see TgsCanvas::insertBlockAction()'s docblock for why it must be nested.
            ->set('mountedActions.1.data.blocks', [$first])
            ->callMountedAction();

        $placements = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->get();

        $this->assertCount(1, $placements);
        $insertedIds = $placements->pluck('block_id')->all();
        $this->assertNotContains($first, $insertedIds); // copy, not the original
        $this->assertNotContains($second, $insertedIds); // unselected block never inserted
    }
}
