<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasCollapseAllButtonTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function collapse_all_toggle_renders_when_not_readonly(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertSeeHtml('tgs-collapse-all');
    }

    #[Test]
    public function collapse_all_toggle_is_hidden_in_readonly(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = HostDescriptor::forModelSlot($demo, 'content');
        app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'Текст']);

        // The substring `tgs-collapse-all` is NOT unique to the toggle button — every node
        // (_node.blade.php) also emits `x-on:tgs-collapse-all.window="…"`, and nodes DO render
        // in readonly mode (only mutating affordances are hidden). Assert on the button's
        // visible label instead, which exists only on the toggle button, not on any node.
        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id, 'readonly' => true])
            ->assertSuccessful()
            ->assertDontSee('Свернуть всё');
    }
}
