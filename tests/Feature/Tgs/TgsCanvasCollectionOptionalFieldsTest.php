<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\Collection;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Task (2026-07-23): Content::blockOptionalFieldsInCollection() declares 'content' as
 * optional when the block lives on a collection canvas (a page skeleton/template, where
 * real content often isn't ready yet) — every other host keeps the field required.
 */
class TgsCanvasCollectionOptionalFieldsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function empty_content_is_allowed_when_editing_on_a_collection_host(): void
    {
        $collection = Collection::factory()->create();
        $host = $collection->canvas('blocks')->hostDescriptor();
        $block = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'Черновик'],
        );

        Livewire::test(TgsCanvas::class, ['canvasId' => $collection->canvas('blocks')->id])
            ->callAction(
                'editBlockAction',
                data: ['content' => ''],
                arguments: ['block' => $block->id, 'type' => 'content', 'isRoot' => true],
            )
            ->assertHasNoErrors()
            ->assertSuccessful();

        // RichEditor normalizes a blank submission to an empty paragraph rather than a raw
        // empty string — the assertion targets that actual persisted shape.
        $this->assertSame('<p></p>', Block::find($block->id)->data['content']);
    }

    #[Test]
    public function empty_content_is_still_rejected_on_a_normal_page_host(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'Черновик'],
        );

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->callAction(
                'editBlockAction',
                data: ['content' => ''],
                arguments: ['block' => $block->id, 'type' => 'content', 'isRoot' => true],
            )
            ->assertHasErrors(['mountedActions.0.data.content']);
    }
}
