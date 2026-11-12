<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Regression: Picture/Gallery::getBlockFields() resolve their own Block row via
 * TgsBlock::resolveMediaHostBlock($get('id')) so the media upload field attaches
 * to the right host. editBlockAction()'s fillForm() used to return only $block->data
 * (a block's DATA payload never contains its own `id` — that's a real Eloquent column,
 * not a form field), so $get('id') resolved null and
 * Block::firstOrCreate(['id' => null], ...) violated blocks.id's NOT NULL constraint —
 * on both adding a NEW media block (auto-opened editBlockAction) and editing an
 * EXISTING one.
 */
class TgsCanvasMediaBlockTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function adding_a_picture_root_block_mounts_the_auto_opened_editor_without_crashing(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('addRootBlock', 'picture')
            ->assertSuccessful();

        $block = Block::query()->where('owner_container_id', (string) $demo->id)->sole();
        $this->assertSame('picture', $block->type);
    }

    #[Test]
    public function adding_a_gallery_root_block_mounts_the_auto_opened_editor_without_crashing(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('addRootBlock', 'gallery')
            ->assertSuccessful();

        $block = Block::query()->where('owner_container_id', (string) $demo->id)->sole();
        $this->assertSame('gallery', $block->type);
    }

    #[Test]
    public function reopening_the_editor_on_an_existing_picture_block_does_not_crash(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->call('addRootBlock', 'picture')->assertSuccessful();

        $blockId = Block::query()->where('owner_container_id', (string) $demo->id)->sole()->id;

        // addRootBlock only drafts a leaf like 'picture' (ADR-020). Commit it directly through
        // the persistence service (bypassing the image upload field's required-validation,
        // irrelevant to this "reopen an existing block" test) to simulate an already-saved
        // picture block, then close the auto-opened modal — since it's now committed,
        // unmountAction() must NOT delete it.
        app(CanvasPersistence::class)->commitDraft(
            $host, $blockId, $host->containerType, $host->containerId, $host->slot, null, ['uuid' => 'existing'],
        );
        $component->call('unmountAction');

        $component->mountAction('editBlockAction', ['block' => $blockId, 'type' => 'picture'])
            ->assertSuccessful();

        // The resolved media-host block must be the SAME row the placement points at,
        // not a second orphaned one created because $get('id') was null.
        $this->assertSame(1, Block::query()->where('owner_container_id', (string) $demo->id)->count());
    }
}
