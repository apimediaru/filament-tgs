<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasBlockMetaLayoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function block_meta_exposes_row_layout_for_double_column(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        /** @var TgsCanvas $component */
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->instance();

        $this->assertSame('row', $component->blockMeta('double_column')['layout']);
    }

    #[Test]
    public function block_meta_exposes_stack_layout_for_a_plain_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        /** @var TgsCanvas $component */
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->instance();

        $this->assertSame('stack', $component->blockMeta('content')['layout']);
    }

    #[Test]
    public function block_meta_falls_back_to_stack_layout_for_an_unknown_type(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        /** @var TgsCanvas $component */
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->instance();

        $this->assertSame('stack', $component->blockMeta('totally-unknown-type')['layout']);
    }
}
