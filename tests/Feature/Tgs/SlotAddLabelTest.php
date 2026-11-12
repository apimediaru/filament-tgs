<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class SlotAddLabelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_single_slot_block_gets_a_generic_add_label_without_the_slot_name(): void
    {
        App::setLocale('ru');
        $demo = TgsDemo::factory()->create();

        /** @var TgsCanvas $canvas */
        $canvas = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->instance();

        $this->assertSame('Добавить блок', $canvas->slotAddLabel('column', 'content'));
    }

    #[Test]
    public function a_multi_slot_block_keeps_the_slot_name_in_the_add_label(): void
    {
        App::setLocale('ru');
        $demo = TgsDemo::factory()->create();

        /** @var TgsCanvas $canvas */
        $canvas = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->instance();

        $this->assertSame('Добавить блок в «Левая колонка»', $canvas->slotAddLabel('double_column', 'left'));
    }

    #[Test]
    public function the_slot_header_is_hidden_for_a_block_with_a_single_slot(): void
    {
        App::setLocale('ru');
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::updateOrCreate(['id' => 'COL-SINGLE'], ['type' => 'column', 'data' => [],
            'owner_container_type' => $demo->getMorphClass(), 'owner_container_id' => (string) $demo->id]);
        BlockPlacement::forceCreate([
            'container_type' => $host->containerType, 'container_id' => $host->containerId, 'slot' => $host->slot,
            'rank' => '000000', 'block_id' => 'COL-SINGLE',
        ]);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertDontSee('Слот:');
    }

    #[Test]
    public function the_slot_header_still_shows_for_a_block_with_multiple_slots(): void
    {
        App::setLocale('ru');
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::updateOrCreate(['id' => 'DC-MULTI'], ['type' => 'double_column', 'data' => [],
            'owner_container_type' => $demo->getMorphClass(), 'owner_container_id' => (string) $demo->id]);
        BlockPlacement::forceCreate([
            'container_type' => $host->containerType, 'container_id' => $host->containerId, 'slot' => $host->slot,
            'rank' => '000000', 'block_id' => 'DC-MULTI',
        ]);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertSee('Слот: Левая колонка');
    }
}
