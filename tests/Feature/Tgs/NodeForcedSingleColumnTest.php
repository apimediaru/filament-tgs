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
 * Stage 2 (node header priority/compaction plan): a "double_column" nested inside another
 * "double_column" stops splitting 50/50 and renders as a single stacked column instead — the
 * root cause of the extreme width collapse the live check found (1280 -> 640 -> 320px), not
 * just a symptom fix. Only row-layout ANCESTORS count toward the depth; a plain stack container
 * (e.g. "column") between two double_columns does not reset or contribute to it.
 */
class NodeForcedSingleColumnTest extends TestCase
{
    use RefreshDatabase;

    private function render(TgsDemo $demo): string
    {
        return Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->html();
    }

    #[Test]
    public function a_double_column_nested_inside_another_double_column_is_forced_to_stack(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $persistence = app(CanvasPersistence::class);

        $outer = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'double_column', []);
        $inner = $persistence->addBlock($host, 'block', $outer->id, 'left', 'double_column', []);
        $persistence->addBlock($host, 'block', $inner->id, 'left', 'content', ['content' => 'a']);
        $persistence->addBlock($host, 'block', $inner->id, 'right', 'content', ['content' => 'b']);

        $html = $this->render($demo);

        // The outer double_column still splits 50/50 — only the nested one is forced to stack.
        $this->assertSame(1, substr_count($html, 'flex gap-3'));
        $this->assertStringContainsString('space-y-2', $html);
    }

    #[Test]
    public function a_root_double_column_still_renders_as_a_row(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $persistence = app(CanvasPersistence::class);

        $outer = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'double_column', []);
        $persistence->addBlock($host, 'block', $outer->id, 'left', 'content', ['content' => 'a']);

        $html = $this->render($demo);

        $this->assertStringContainsString('flex gap-3', $html);
    }

    #[Test]
    public function a_double_column_nested_inside_a_plain_stack_container_is_not_forced(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $persistence = app(CanvasPersistence::class);

        // "column" is a plain stack-layout container — it doesn't count as a row-layout
        // ancestor, so a double_column nested inside it is the FIRST row-layout in the chain.
        $outer = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $inner = $persistence->addBlock($host, 'block', $outer->id, 'content', 'double_column', []);
        $persistence->addBlock($host, 'block', $inner->id, 'left', 'content', ['content' => 'a']);

        $html = $this->render($demo);

        $this->assertStringContainsString('flex gap-3', $html);
    }
}
