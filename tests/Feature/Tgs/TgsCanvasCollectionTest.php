<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Collection;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasCollectionTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> ids of the 3 blocks added to the collection's 'blocks' group */
    private function collectionWithThreeBlocks(Collection $collection): array
    {
        $host = $collection->canvas('blocks')->hostDescriptor();
        $persistence = app(CanvasPersistence::class);

        $ids = [];
        foreach (['first', 'second', 'third'] as $text) {
            $block = $persistence->addBlock(
                $host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => $text],
            );
            $ids[] = $block->id;
        }

        return $ids;
    }

    #[Test]
    public function collection_blocks_lists_the_top_level_blocks_of_the_collection(): void
    {
        $collection = Collection::factory()->create();
        $ids = $this->collectionWithThreeBlocks($collection);

        $demo = TgsDemo::factory()->create();
        $instance = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->instance();

        // @phpstan-ignore method.notFound (Livewire component method, ->instance() is typed as the base Component)
        $nodes = $instance->collectionBlocks($collection->id);

        $this->assertCount(3, $nodes);
        $this->assertSame($ids, array_column($nodes, 'id'));
        foreach ($nodes as $node) {
            $this->assertSame('content', $node['type']);
            $this->assertArrayHasKey('label', $node);
            $this->assertArrayHasKey('name', $node);
            $this->assertArrayHasKey('preview', $node);
        }
    }

    #[Test]
    public function collection_blocks_returns_empty_for_an_unknown_collection(): void
    {
        $demo = TgsDemo::factory()->create();
        $instance = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->instance();

        // @phpstan-ignore method.notFound (Livewire component method, ->instance() is typed as the base Component)
        $this->assertSame([], $instance->collectionBlocks(999999));
    }

    #[Test]
    public function insert_from_collection_inserts_exactly_the_selected_blocks_as_copies(): void
    {
        $collection = Collection::factory()->create();
        [$first, $second, $third] = $this->collectionWithThreeBlocks($collection);

        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->call('insertFromCollection', $collection->id, [$first, $second, 'BOGUS-ID']);

        $placements = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->get();

        // Exactly 2 placements landed — the bogus id was ignored, the unselected 3rd was not inserted.
        $this->assertCount(2, $placements);

        $insertedBlockIds = $placements->pluck('block_id')->all();

        // Copies, not references or the originals themselves: new ids, owned by the target page.
        $this->assertNotContains($first, $insertedBlockIds);
        $this->assertNotContains($second, $insertedBlockIds);
        $this->assertNotContains($third, $insertedBlockIds);

        foreach ($insertedBlockIds as $blockId) {
            $block = Block::find($blockId);
            $this->assertNotNull($block);
            $this->assertSame($host->containerType, $block->owner_container_type);
            $this->assertSame($host->containerId, $block->owner_container_id);
        }

        // The third (unselected) source block never appears on the target page as a copy or reference.
        $this->assertFalse(
            BlockPlacement::query()->where('container_type', $demo->canvasDescriptor('content')->containerType)->where('slot', 'content')->where('container_id', (string) $demo->id)->where('block_id', $third)->exists()
        );
    }

    #[Test]
    public function insert_from_collection_with_an_unknown_collection_id_is_a_no_op(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->call('insertFromCollection', 999999, ['whatever']);

        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->count());
    }

    #[Test]
    public function get_collections_property_excludes_a_provisional_collection(): void
    {
        $real = Collection::factory()->create(['name' => 'Real collection']);
        Collection::provisional();

        $demo = TgsDemo::factory()->create();
        $instance = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->instance();

        // @phpstan-ignore method.notFound (Livewire computed getter, resolved via magic __call on the base Component)
        $collections = $instance->getCollectionsProperty();
        $ids = array_column($collections, 'id');

        $this->assertContains($real->id, $ids);
        $this->assertCount(1, $collections);
    }

    /**
     * Task 5: naming a block via the edit modal's `_name` field must also work on a
     * collection's own canvas (not just the library host) — collectionBlocks() already
     * surfaces `name` to Task 4's picker modal, so the field must be editable here too,
     * or a name typed on the collection canvas would silently fail to persist.
     */
    #[Test]
    public function editing_a_block_on_a_collection_host_updates_its_name(): void
    {
        $collection = Collection::factory()->create();
        [$blockId] = $this->collectionWithThreeBlocks($collection);

        Livewire::test(TgsCanvas::class, ['canvasId' => $collection->canvas('blocks')->id])
            ->callAction(
                'editBlockAction',
                data: ['content' => 'first', '_name' => 'Именованный блок'],
                arguments: ['block' => $blockId, 'type' => 'content', 'isRoot' => true],
            )
            ->assertHasNoErrors()
            ->assertSuccessful();

        $updated = Block::find($blockId);
        $this->assertSame('Именованный блок', $updated->name);
        $this->assertArrayNotHasKey('_name', $updated->data);

        $nodes = collect(Livewire::test(TgsCanvas::class, ['canvasId' => $collection->canvas('blocks')->id])
            ->instance()
            // @phpstan-ignore method.notFound (Livewire component method, ->instance() is typed as the base Component)
            ->collectionBlocks($collection->id));

        $this->assertSame('Именованный блок', $nodes->firstWhere('id', $blockId)['name']);
    }
}
