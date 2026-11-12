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

class TgsCanvasPickerDrawerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function opening_the_picker_for_root_stores_no_parent(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        // "Open" is now insertBlockAction() being mounted (a native ->slideOver()), not a
        // bespoke pickerOpen boolean — see TgsCanvas::openPicker()'s docblock.
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('openPicker')
            ->assertSet('pickerParentId', null)
            ->assertSet('pickerSlot', null);

        $this->assertSame('insertBlockAction', $component->get('mountedActions')[0]['name']);
    }

    #[Test]
    public function choosing_a_block_for_root_adds_it_and_closes_the_picker(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        // 'code' is a leaf type — addRootBlock() drafts it and auto-opens editBlockAction
        // (ADR-020); chooseBlock() closes the picker BEFORE that happens (see its own
        // docblock), so the picker itself is gone and editBlockAction mounts as a plain
        // TOP-LEVEL action, not nested under insertBlockAction.
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('openPicker')
            ->call('chooseBlock', 'code');

        $this->assertSame('editBlockAction', $component->get('mountedActions')[0]['name']);
        $this->assertCount(1, $component->get('mountedActions'));
        $this->assertSame(1, Block::query()->where('owner_container_id', (string) $demo->id)->count());
    }

    #[Test]
    public function choosing_a_block_for_a_slot_adds_it_under_the_parent(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->call('addRootBlock', 'double_column');
        $parentId = Block::query()->where('owner_container_id', (string) $demo->id)->sole()->id;

        // 'column' (a container) commits+places immediately (ADR-020); a leaf like 'code' would
        // only draft until the edit modal is submitted, leaving no BlockPlacement to assert on.
        // Containers don't open any modal via addChildBlock(), so the picker closing leaves no
        // action mounted at all here (unlike the leaf-type case in the test above).
        $component->call('openPicker', $parentId, 'left')
            ->call('chooseBlock', 'column');

        $this->assertSame([], $component->get('mountedActions'));

        $this->assertTrue(
            BlockPlacement::query()
                ->where('container_type', 'block')
                ->where('container_id', $parentId)
                ->where('slot', 'left')
                ->exists(),
        );
    }

    #[Test]
    public function opening_the_picker_for_a_foreign_parent_is_ignored(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        $otherDemo = TgsDemo::factory()->create();
        $otherGroup = $otherDemo->canvas('content');
        $foreign = Livewire::test(TgsCanvas::class, ['canvasId' => $otherGroup->id]);
        $foreign->call('addRootBlock', 'double_column');
        $foreignParentId = Block::query()->where('owner_container_id', (string) $otherDemo->id)->sole()->id;

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('openPicker', $foreignParentId, 'left');

        $this->assertSame([], $component->get('mountedActions'));
    }

    #[Test]
    public function picker_is_not_filtered_for_the_root(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->call('openPicker');

        /** @var TgsCanvas $instance */
        $instance = $component->instance();
        $this->assertFalse($instance->getPickerIsFilteredProperty());
    }

    #[Test]
    public function picker_is_filtered_for_a_slot_with_restricted_allowed_children(): void
    {
        $this->markTestSkipped(
            'no block in this codebase declares a restricted allowedChildren slot to exercise this against',
        );
    }
}
