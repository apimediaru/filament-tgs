<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * «Нет содержимого» вводило в заблуждение для листовых блоков без превью (напр. Code) —
 * оно означает «контейнер пуст», а не «поля не заполнены». Листовые блоки (blockSlots() === [])
 * без превью-строки теперь показывают «Заполнено»/«Не заполнено»; «Нет содержимого» остаётся
 * только для блоков-контейнеров с пустым слотом.
 */
class TgsCanvasFilledStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_filled_leaf_block_without_preview_shows_filled(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->code(['code' => 'console.log(1)', 'is_pure' => false])->withId('FILLED-CODE')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'FILLED-CODE']);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->assertSuccessful();
        $html = $component->html();

        $this->assertStringContainsString(__('tgs::filament.tgs.canvas.filled'), $html);
        $this->assertStringNotContainsString(__('tgs::filament.tgs.canvas.not_filled'), $html);
        $this->assertStringNotContainsString(__('tgs::filament.tgs.canvas.empty'), $html);
    }

    #[Test]
    public function an_empty_leaf_block_without_preview_shows_not_filled(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->ofType(ContentBlockType::Code)->withId('EMPTY-CODE')->create([
            'data' => [],
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'EMPTY-CODE']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertSee(__('tgs::filament.tgs.canvas.not_filled'));
    }

    #[Test]
    public function an_empty_container_block_still_shows_no_content(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->column()->withId('EMPTY-COLUMN')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'EMPTY-COLUMN']);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->assertSuccessful();
        $html = $component->html();

        $this->assertStringContainsString(__('tgs::filament.tgs.canvas.empty'), $html);
        $this->assertStringNotContainsString(__('tgs::filament.tgs.canvas.filled'), $html);
        $this->assertStringNotContainsString(__('tgs::filament.tgs.canvas.not_filled'), $html);
    }
}
