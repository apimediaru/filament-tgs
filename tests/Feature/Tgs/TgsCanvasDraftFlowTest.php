<?php

declare(strict_types=1);

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

class TgsCanvasDraftFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function adding_a_leaf_creates_an_uncommitted_unplaced_draft_and_opens_the_editor(): void
    {
        $demo = TgsDemo::factory()->create();

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('addRootBlock', 'content')
            ->assertActionMounted('editBlockAction');

        $block = Block::query()->where('owner_container_id', (string) $demo->id)->sole();
        $this->assertNull($block->committed_at);
        $this->assertSame(0, BlockPlacement::query()->where('block_id', $block->id)->count()); // невидим — нет в дереве
    }

    #[Test]
    public function submitting_the_editor_commits_and_places_the_draft(): void
    {
        $demo = TgsDemo::factory()->create();
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('addRootBlock', 'content');
        $blockId = Block::query()->where('owner_container_id', (string) $demo->id)->sole()->id;

        // addRootBlock auto-opens editBlockAction for the fresh draft (ADR-020) with the
        // placement target already in the mounted action's arguments — submit that SAME
        // mounted action (fillForm + callMountedAction), rather than closing/reopening it
        // (which would delete the still-uncommitted draft per unmountAction()'s new contract).
        $component->fillForm(['content' => 'X'])->callMountedAction();

        $this->assertNotNull(Block::find($blockId)->committed_at);
        $this->assertSame(1, BlockPlacement::query()->where('block_id', $blockId)->count());
    }

    #[Test]
    public function closing_the_editor_without_committing_deletes_the_draft(): void
    {
        $demo = TgsDemo::factory()->create();
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('addRootBlock', 'content');
        $blockId = Block::query()->where('owner_container_id', (string) $demo->id)->sole()->id;

        $component->call('unmountAction'); // отмена/закрытие модалки

        $this->assertNull(Block::find($blockId)); // черновик удалён
    }

    #[Test]
    public function cancelling_an_edit_of_a_committed_block_keeps_it(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'keep'],
        );

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id]);
        $component->call('mountAction', 'editBlockAction', ['block' => $block->id, 'type' => 'content', 'isRoot' => true]);
        $component->call('unmountAction');

        $this->assertNotNull(Block::find($block->id)); // committed — не удаляется
    }
}
