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
 * Node header priority/compaction plan, Task D (revised after live review — a full-width name
 * badge looked oversized and out of place next to other rows): a named block shows a small,
 * shrink-0 icon badge instead — the name text moves into its tooltip — and the preview line
 * keeps rendering inline in the same row, exactly like an unnamed block.
 */
class NodeNamePreviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_named_block_shows_a_compact_icon_badge_with_the_name_in_a_tooltip(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->content(['content' => 'Контактный текст подвала'])->withId('NAMED-1')->create([
            'name' => 'Контакты — подвал',
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'NAMED-1']);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->html();

        $this->assertStringContainsString('data-testid="node-name"', $html);
        // The name is NOT visible inline as text — only inside the tooltip attribute.
        $this->assertDoesNotMatchRegularExpression('/<[a-z]+[^>]*data-testid="node-name"[^>]*>\s*Контакты/u', $html);
        $this->assertStringContainsString('Контакты — подвал', html_entity_decode($html, ENT_QUOTES));
        // The badge itself must be shrink-0 (compact), not flex-1 (full-width).
        $this->assertMatchesRegularExpression('/data-testid="node-name"[^>]*shrink-0/', $html);
        // The preview line still renders inline in the same row, exactly like an unnamed block.
        $this->assertMatchesRegularExpression('/&middot;\s*Контактный текст подвала/', $html);
    }

    #[Test]
    public function an_unnamed_block_still_shows_the_preview_inline(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->content(['content' => 'Просто текст'])->withId('UNNAMED-1')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'UNNAMED-1']);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->html();

        $this->assertStringNotContainsString('data-testid="node-name"', $html);
        $this->assertMatchesRegularExpression('/&middot;\s*Просто текст/', $html);
    }
}
