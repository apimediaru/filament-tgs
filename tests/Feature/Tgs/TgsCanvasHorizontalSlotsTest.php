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

class TgsCanvasHorizontalSlotsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function double_column_renders_its_slots_in_a_flex_row(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->doubleColumn()->withId('ROW-DC')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'ROW-DC']);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->assertSuccessful();
        $html = $component->html();

        $this->assertStringContainsString('flex gap-3', $html);
    }

    #[Test]
    public function a_stack_layout_block_still_renders_its_slots_stacked(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->column()->withId('STACK-COL')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'STACK-COL']);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->assertSuccessful();
        $html = $component->html();

        $this->assertStringContainsString('space-y-2', $html);
        $this->assertStringNotContainsString('flex gap-3', $html);
    }
}
