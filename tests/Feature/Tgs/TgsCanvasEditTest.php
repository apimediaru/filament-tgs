<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasEditTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function editing_a_block_updates_its_data(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->call('addRootBlock', 'content')->assertSuccessful();

        $blockId = Block::query()->where('owner_container_id', (string) $demo->id)->sole()->id;

        // addRootBlock only drafts leaf blocks now (ADR-020, Task 4): commit through the
        // auto-opened modal first so the row is a normal, already-placed block — submitting it
        // also auto-unmounts the action (Filament's InteractsWithActions::callMountedAction()),
        // so the callAction() below mounts fresh rather than nesting under it.
        $component->fillForm(['content' => 'Черновой'])->callMountedAction();

        $component->callAction(
            'editBlockAction',
            data: ['content' => 'Новый'],
            arguments: ['block' => $blockId, 'type' => 'content'],
        )->assertSuccessful();

        $this->assertStringContainsString('Новый', Block::find($blockId)->data['content']);
    }

    /**
     * Regression for the data-loss bug (plan B2 review, fix): editBlockAction()'s modal used
     * to render ONLY the general tab (getBlockFields()), so submitting it wholesale-replaced
     * Block::data (CanvasPersistence::editBlockData()) with just that subset — silently
     * dropping heading/section/container keys set via the old Filament Builder's four-tab
     * schema. The fix expands the modal schema to all four tabs so a submit always produces
     * a complete $data.
     */
    #[Test]
    public function editing_a_block_preserves_heading_and_section_data_from_other_tabs(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->call('addRootBlock', 'content')->assertSuccessful();

        $blockId = Block::query()->where('owner_container_id', (string) $demo->id)->sole()->id;

        // addRootBlock only drafts leaf blocks now (ADR-020, Task 4): commit through the
        // auto-opened modal first, then simulate a block previously edited via the old
        // Filament Builder: data spans general (content) + heading (section_heading) +
        // section (section_size) tabs. Submitting also auto-unmounts the action, so the
        // callAction() below mounts fresh rather than nesting under it.
        $component->fillForm(['content' => 'Черновой'])->callMountedAction();

        Block::find($blockId)->update([
            'data' => [
                'content' => 'Исходный текст',
                'section_heading' => 'Моя секция',
                'section_size' => 'full',
            ],
        ]);

        // Edit through the canvas modal, changing ONLY the general-tab field. Root block,
        // so the container tab is also present in the schema (though unused here).
        $component->callAction(
            'editBlockAction',
            data: [
                'content' => 'Новый текст',
                'section_heading' => 'Моя секция',
                'section_size' => 'full',
            ],
            arguments: ['block' => $blockId, 'type' => 'content', 'isRoot' => true],
        )->assertHasNoErrors()->assertSuccessful();

        $data = Block::find($blockId)->data;

        $this->assertStringContainsString('Новый текст', $data['content']);
        $this->assertSame('Моя секция', $data['section_heading']);
        $this->assertSame('full', $data['section_size']);
    }
}
