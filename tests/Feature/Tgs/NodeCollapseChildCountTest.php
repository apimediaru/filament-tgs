<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Node header priority/compaction plan, Task C: a container's child count moves out of the
 * preview line ("N вложенных блоков" text competing with the preview) to a small number next
 * to the collapse/expand chevron — the preview line stays empty for such a container instead.
 */
class NodeCollapseChildCountTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_container_with_children_shows_the_count_near_the_chevron_not_in_the_preview(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $persistence = app(CanvasPersistence::class);

        $column = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $persistence->addBlock($host, 'block', $column->id, 'content', 'content', ['content' => 'a']);
        $persistence->addBlock($host, 'block', $column->id, 'content', 'content', ['content' => 'b']);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->html();

        $this->assertStringContainsString('data-testid="child-count"', $html);
        $this->assertMatchesRegularExpression('/data-testid="child-count"[^>]*>\s*2\s*</', $html);
        $this->assertStringNotContainsString(trans_choice('tgs::filament.tgs.canvas.nested_count', 2, ['count' => 2]), $html);
    }

    #[Test]
    public function a_container_without_children_still_shows_the_empty_placeholder(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $persistence = app(CanvasPersistence::class);

        $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->html();

        $this->assertStringNotContainsString('data-testid="child-count"', $html);
        $this->assertStringContainsString(__('tgs::filament.tgs.canvas.empty'), $html);
    }
}
