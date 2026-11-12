<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Task 2 (tgs-canvas as entities): "Заменить исходный блок ссылкой" — an opt-in checkbox on
 * the "add to library" modal that, instead of a plain independent copy, atomically extracts
 * the block into the library and replaces the original with a reference at the same
 * position (CanvasPersistence::extractToLibrary).
 */
class ExtractToLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function libraryHost(): HostDescriptor
    {
        return Canvas::named(TgsNamedCanvas::BlockLibrary)->hostDescriptor();
    }

    #[Test]
    public function extract_copies_to_library_and_replaces_the_original_with_a_reference(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $persistence = app(CanvasPersistence::class);

        // Two siblings so the position-capture matters: the block being extracted sits at
        // position 1, with a neighbor after it that must NOT shift once the reference lands
        // back in the same slot.
        $first = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'first']);
        $target = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'extract me']);
        $after = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'after']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('addToLibraryAction', ['block' => $target->id, 'parent' => null, 'slot' => $host->slot])
            ->setActionData(['name' => 'Извлечённый', 'replace_with_reference' => true])
            ->callMountedAction()
            ->assertHasNoErrors();

        // Original placement gone — no placement for the OLD block id remains on the page.
        $this->assertFalse(
            BlockPlacement::query()->where('container_type', $host->containerType)->where('container_id', $host->containerId)
                ->where('slot', $host->slot)->where('block_id', $target->id)->exists()
        );

        // A reference to a NEW library-owned block now sits in the SAME relative spot (between
        // $first and $after) that the original occupied, with the neighbors untouched.
        $placements = BlockPlacement::query()
            ->where('container_type', $host->containerType)->where('container_id', $host->containerId)->where('slot', $host->slot)
            ->orderBy('rank')->get(['rank', 'block_id']);

        $this->assertCount(3, $placements);
        $this->assertSame($first->id, $placements[0]->block_id);
        $this->assertNotSame($target->id, $placements[1]->block_id);
        $this->assertSame($after->id, $placements[2]->block_id);

        $libraryCopyId = $placements[1]->block_id;
        $libraryCopy = Block::find($libraryCopyId);
        $libHost = $this->libraryHost();

        // The library copy is owned by the library host, named, and self-contained.
        $this->assertSame($libHost->containerType, $libraryCopy->owner_container_type);
        $this->assertSame($libHost->containerId, $libraryCopy->owner_container_id);
        $this->assertSame('Извлечённый', $libraryCopy->name);

        // The library copy has its own placement in the library slot too.
        $this->assertTrue(
            BlockPlacement::query()->where('container_type', $libHost->containerType)->where('container_id', $libHost->containerId)
                ->where('slot', $libHost->slot)->where('block_id', $libraryCopyId)->exists()
        );

        // The extracted original block was soft-deleted (owned subtree cascade), not left
        // orphaned — physically gone would mean it can no longer be recovered from the trash.
        $this->assertNotNull(Block::find($target->id)?->deleted_at);
    }

    #[Test]
    public function plain_add_to_library_keeps_the_original(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $persistence = app(CanvasPersistence::class);

        $target = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'stay put']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('addToLibraryAction', ['block' => $target->id, 'parent' => null, 'slot' => $host->slot])
            ->setActionData(['name' => '', 'replace_with_reference' => false])
            ->callMountedAction()
            ->assertHasNoErrors();

        // Original placement/block untouched.
        $this->assertTrue(
            BlockPlacement::query()->where('container_type', $host->containerType)->where('container_id', $host->containerId)
                ->where('slot', $host->slot)->where('block_id', $target->id)->exists()
        );
        $this->assertSame($host->containerType, Block::find($target->id)->owner_container_type);

        // Independent copy landed in the library.
        $libHost = $this->libraryHost();
        $libCopyId = BlockPlacement::query()
            ->where('container_type', $libHost->containerType)->where('container_id', $libHost->containerId)
            ->value('block_id');

        $this->assertNotNull($libCopyId);
        $this->assertNotSame($target->id, $libCopyId);
        $this->assertSame($libHost->containerType, Block::find($libCopyId)->owner_container_type);
    }
}
