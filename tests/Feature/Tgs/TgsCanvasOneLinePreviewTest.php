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

class TgsCanvasOneLinePreviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function title_and_preview_no_longer_render_as_two_separate_lines(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->content(['content' => 'Мой превью-текст'])->withId('ONELINE-1')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'ONELINE-1']);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->assertSuccessful();
        $html = $component->html();

        // The old two-line layout put the preview in its OWN, always-visible `line-clamp-2`
        // div, separate from the label; the new one-line layout joins them with a middle dot
        // in the SAME row instead. `line-clamp-2` still exists in the markup (Stage 2's
        // `data-testid="preview-block"`, hidden above the 360px `@container` breakpoint,
        // wraps the preview onto two lines below the header on a narrow container) — this
        // just confirms it's not on the always-visible inline row.
        $this->assertStringContainsString('Содержимое', $html);
        $this->assertStringContainsString('Мой превью-текст', $html);
        $this->assertStringContainsString('data-testid="preview-block"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<span class="min-w-0 flex-1 truncate[^"]*line-clamp-2/',
            $html,
        );
    }

    #[Test]
    public function empty_leaf_block_shows_the_not_filled_placeholder_inline(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        // Content — листовой блок (blockSlots() === []); с пустым previewFields-полем он
        // не «пустой контейнер», а «не заполнен» (см. TgsCanvasFilledStatusTest для
        // разделения «Заполнено/Не заполнено» vs «Нет содержимого»).
        Block::factory()->content(['content' => null])->withId('ONELINE-EMPTY')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId,
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'ONELINE-EMPTY']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertSee(__('tgs::filament.tgs.canvas.not_filled'));
    }
}
