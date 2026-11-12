<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Node header priority/compaction plan, Stage 2 (revised after live review): the header row
 * itself never reflows — only the preview text moves. It renders inline (one line, truncated)
 * next to the header on a wide container, and — via a `@container` CSS toggle, no JS — as a
 * separate full-width block below the header on a narrow one, wrapping onto two lines instead
 * of a single truncated line.
 */
class NodePreviewCardModeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_header_row_carries_no_responsive_stacking_classes(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->content(['content' => 'текст'])->withId('CARD-1')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'CARD-1']);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->html();

        // The header must never switch to a vertical stack — only the preview toggles.
        $this->assertStringNotContainsString('flex-col', $html);
    }

    #[Test]
    public function the_preview_renders_both_inline_and_as_a_hidden_narrow_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->content(['content' => 'Уникальный превью текст'])->withId('CARD-2')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'CARD-2']);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->html();

        $this->assertSame(2, substr_count($html, 'Уникальный превью текст'));
        $this->assertStringContainsString('data-testid="preview-block"', $html);
        // The inline span hides itself under the breakpoint; the block below only appears
        // above it — CSS toggle, no duplicated visibility logic in PHP.
        $this->assertMatchesRegularExpression('/class="[^"]*truncate[^"]*@max-\[360px\]:hidden/', $html);
        $this->assertMatchesRegularExpression('/data-testid="preview-block" class="[^"]*hidden @max-\[360px\]:line-clamp-2/', $html);
    }
}
