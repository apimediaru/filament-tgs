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

class TgsCanvasLayoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_the_full_layout_with_preview_and_no_autosave_indicator_yet(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->content(['content' => 'Видимый текст превью'])->withId('LAYOUT-1')->create([
            'owner_container_type' => $demo->getMorphClass(), 'owner_container_id' => (string) $demo->id,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'LAYOUT-1']);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->assertSuccessful();
        $component->assertSee('Видимый текст превью');
        $this->assertNull($component->get('savedAt'));

        // до первой мутации индикатор не показывает "Сохранено"
        $this->assertStringNotContainsString('Сохранено', $component->html());
    }

    #[Test]
    public function a_mutating_call_stamps_savedat_and_the_autosave_indicator_shows_it(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        // 'column' (container) commits+places immediately (ADR-020) and thus stamps savedAt;
        // a leaf would only draft until the edit modal is submitted, deferring the mutation.
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->call('addRootBlock', 'column');
        $component->assertSuccessful();

        $savedAt = $component->get('savedAt');
        $this->assertNotNull($savedAt);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $savedAt);

        $component->assertSeeHtml('Сохранено');
        $component->assertSeeHtml($savedAt);
    }

    #[Test]
    public function an_empty_slot_shows_the_add_block_affordance_instead_of_blank_space(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        // column-блок имеет слот 'content', но без детей — должен показать пунктирную
        // "Добавить блок" заглушку, а не пустой блок.
        Block::factory()->column()->withId('EMPTY-COL')->create([
            'owner_container_type' => $demo->getMorphClass(), 'owner_container_id' => (string) $demo->id,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'EMPTY-COL']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertSee('+ Добавить блок');
    }
}
