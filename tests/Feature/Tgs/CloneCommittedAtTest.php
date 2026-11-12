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

class CloneCommittedAtTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_duplicated_block_is_committed(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);
        $src = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'A']);

        $clone = $p->duplicateBlock($host, $host->containerType, $host->containerId, $host->slot, $src->id);

        $this->assertNotNull(Block::find($clone->id)->committed_at);
    }

    #[Test]
    public function editing_a_duplicated_block_updates_its_data_not_the_draft_branch(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);
        $src = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'A']);
        $clone = $p->duplicateBlock($host, $host->containerType, $host->containerId, $host->slot, $src->id);

        // Правка клона по шестерёнке (без containerType/position аргументов — как реальный edit).
        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->callAction('editBlockAction', data: ['content' => 'Edited'], arguments: [
                'block' => $clone->id, 'type' => 'content', 'isRoot' => true,
            ]);

        $this->assertStringContainsString('Edited', Block::find($clone->id)->data['content']);
    }

    #[Test]
    public function a_detached_block_is_committed(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);
        $foreign = Block::updateOrCreate(['id' => 'FGN'], [
            'type' => 'content', 'data' => [], 'owner_container_type' => 'settings',
            'owner_container_id' => 'block_library.value', 'committed_at' => now(),
        ]);
        $p->insertReference($host, $host->containerType, $host->containerId, $host->slot, 'FGN');

        $clone = $p->detachBlock($host, $host->containerType, $host->containerId, $host->slot, 'FGN');

        $this->assertNotNull(Block::find($clone->id)->committed_at);
    }
}
