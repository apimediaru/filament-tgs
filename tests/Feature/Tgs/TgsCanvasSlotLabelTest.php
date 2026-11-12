<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasSlotLabelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_falls_back_to_the_raw_key_for_an_unknown_slot(): void
    {
        $group = $this->makeGroup();

        /** @var TgsCanvas $component */
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->instance();

        $this->assertSame('nonexistent', $component->slotLabel('nonexistent'));
    }

    #[Test]
    public function it_resolves_the_russian_label_for_a_known_slot(): void
    {
        App::setLocale('ru');
        $group = $this->makeGroup();

        /** @var TgsCanvas $component */
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->instance();

        $this->assertSame('Левая колонка', $component->slotLabel('left'));
    }

    #[Test]
    public function it_renders_the_localized_label_in_the_slot_header(): void
    {
        App::setLocale('ru');
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->doubleColumn()->withId('COL-1')->create([
            'owner_container_type' => $demo->getMorphClass(), 'owner_container_id' => (string) $demo->id]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'COL-1']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertSee('Левая колонка')
            ->assertDontSee('Слот: left');
    }

    private function makeGroup(): Canvas
    {
        $demo = TgsDemo::factory()->create();

        return $demo->canvas('content');
    }
}
