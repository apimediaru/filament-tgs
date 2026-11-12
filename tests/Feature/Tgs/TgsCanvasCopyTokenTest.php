<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasCopyTokenTest extends TestCase
{
    use RefreshDatabase;

    private function placedBlock(TgsDemo $demo): string
    {
        $host = $demo->canvas('content')->hostDescriptor();
        Block::factory()->content(['content' => 'x'])->withId('COPY-TOKEN-1')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'COPY-TOKEN-1']);

        return 'COPY-TOKEN-1';
    }

    #[Test]
    public function copy_button_renders_and_writes_the_blocks_own_id_to_the_clipboard_call(): void
    {
        $demo = TgsDemo::factory()->create();
        $blockId = $this->placedBlock($demo);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id]);
        $component->assertSuccessful();
        $html = $component->html();

        $this->assertStringContainsString('data-testid="copy-token"', $html);
        $this->assertStringContainsString("navigator.clipboard.writeText('{$blockId}')", $html);
    }

    #[Test]
    public function copy_button_renders_in_readonly(): void
    {
        $demo = TgsDemo::factory()->create();
        $this->placedBlock($demo);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id, 'readonly' => true])
            ->assertSuccessful()
            ->assertSeeHtml('data-testid="copy-token"');
    }
}
