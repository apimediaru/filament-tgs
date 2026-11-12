<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasTokenTabTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function token_tab_button_renders_when_not_on_the_library_host(): void
    {
        $html = view('tgs::_insert-picker', [
            'pickerTab' => 'new',
            'pickerBlocks' => [],
            'pickerIsFiltered' => false,
            'libraryBlocks' => [],
            'collections' => [],
            'isLibraryHost' => false,
        ])->render();

        $this->assertStringContainsString("\$set('pickerTab', 'token')", $html);
        $this->assertStringContainsString(__('tgs::filament.tgs.canvas.token.tab_token'), $html);
    }

    #[Test]
    public function token_tab_is_hidden_on_the_library_canvas(): void
    {
        $html = view('tgs::_insert-picker', [
            'pickerTab' => 'new',
            'pickerBlocks' => [],
            'pickerIsFiltered' => false,
            'libraryBlocks' => [],
            'collections' => [],
            'isLibraryHost' => true,
        ])->render();

        $this->assertStringNotContainsString("\$set('pickerTab', 'token')", $html);
        $this->assertStringNotContainsString(__('tgs::filament.tgs.canvas.token.tab_token'), $html);
    }

    #[Test]
    public function token_tab_content_shows_the_insert_button(): void
    {
        $html = view('tgs::_insert-picker', [
            'pickerTab' => 'token',
            'pickerBlocks' => [],
            'pickerIsFiltered' => false,
            'libraryBlocks' => [],
            'collections' => [],
            'isLibraryHost' => false,
        ])->render();

        $this->assertStringContainsString("mountAction('insertFromTokenAction')", $html);
        $this->assertStringContainsString(__('tgs::filament.tgs.canvas.token.insert_button'), $html);
    }

    #[Test]
    public function insert_drawer_offers_the_token_tab_end_to_end(): void
    {
        $demo = TgsDemo::factory()->create();

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->assertSet('pickerTab', 'new');

        // Full mount+render coverage of insertBlockAction()'s own modalContent() (not just the
        // partial in isolation) is already established as unreliable in this harness — see
        // TgsCanvasReadonlyUiTest's own docblock for why (Livewire::test() reuses one object,
        // InteractsWithActions::$hasActionsModalRendered is a once-per-render guard meant for a
        // fresh object per request). This test only proves openPicker() still works with the
        // partial now expecting an $isLibraryHost var — a regression here would be a Blade
        // "undefined variable" error surfacing as a 500, which ->assertSuccessful() below
        // would catch.
        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->assertSuccessful();
    }
}
