<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Task 4 (read-only design spec, §3): editBlockAction() adapts in place rather than being
 * swapped for a bespoke second action — same Action name/schema/fillForm, but in read-only:
 * the schema container is disabled (Filament's isDisabled() propagates DOWN the container
 * chain, per §2/§3 — no per-field changes needed) and the modal submit action is suppressed
 * (only the cancel/close action remains). The mutating action() closure itself is untouched —
 * Task 1's guardWritable() there is the actual enforcement; this task is only about what the
 * modal renders and offers, not a second line of defense.
 */
class TgsCanvasReadonlyViewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function in_readonly_the_modal_shows_the_blocks_real_data_with_fields_disabled(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'Реальный текст блока'],
        );

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id, 'readonly' => true])
            ->mountAction('editBlockAction', arguments: ['block' => $block->id, 'type' => 'content', 'isRoot' => true])
            ->assertSchemaStateSet(['content' => '<p>Реальный текст блока</p>'])
            ->assertSchemaComponentExists('content', checkComponentUsing: fn ($component): bool => $component->isDisabled());
    }

    #[Test]
    public function in_readonly_opening_a_foreign_reference_fills_the_schema_from_the_source_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        // A block owned by the Block Library (a different host) — so it is "foreign"
        // when referenced into $demo's canvas.
        $libHost = Canvas::named(TgsNamedCanvas::BlockLibrary)->hostDescriptor();
        $source = Block::updateOrCreate(['id' => 'RO-VIEW-FOREIGN'], [
            'type' => 'content', 'data' => ['content' => 'Текст из источника'],
            'owner_container_type' => $libHost->containerType, 'owner_container_id' => $libHost->containerId,
        ]);
        $source->forceFill(['committed_at' => now()])->save();
        app(CanvasPersistence::class)->insertReference($host, $host->containerType, $host->containerId, $host->slot, $source->id);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id, 'readonly' => true])
            ->mountAction('editBlockAction', arguments: ['block' => $source->id, 'type' => 'content', 'isRoot' => true])
            ->assertSchemaStateSet(['content' => '<p>Текст из источника</p>'])
            ->assertSchemaComponentExists('content', checkComponentUsing: fn ($component): bool => $component->isDisabled());
    }

    #[Test]
    public function in_readonly_the_modal_heading_reads_as_a_view_not_an_edit(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'x'],
        );

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id, 'readonly' => true])
            ->mountAction('editBlockAction', arguments: ['block' => $block->id, 'type' => 'content', 'isRoot' => true]);

        // @phpstan-ignore method.notFound (Livewire component method, ->instance() is typed as the base Component)
        $action = $component->instance()->getMountedActions()[0];

        $this->assertSame(__('tgs::filament.tgs.canvas.block_modal.view', ['block' => 'Содержимое']), $action->getModalHeading());
    }

    #[Test]
    public function in_readonly_the_modal_has_no_submit_action_only_a_way_to_close_it(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'x'],
        );

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id, 'readonly' => true])
            ->mountAction('editBlockAction', arguments: ['block' => $block->id, 'type' => 'content', 'isRoot' => true]);

        // @phpstan-ignore method.notFound (Livewire component method, ->instance() is typed as the base Component)
        $action = $component->instance()->getMountedActions()[0];

        $this->assertNull($action->getModalSubmitAction());
        $this->assertNotNull($action->getModalCancelAction());
    }

    /**
     * Filament's `disabled()`/`disabledSchema()` is explicitly documented as not a security
     * boundary — dehydration is what actually stops disabled data reaching the server, and this
     * canvas has its own bespoke mutating endpoints rather than relying on that. So this test
     * proves the OTHER half: even if a submit were somehow forced through (bypassing the
     * suppressed footer action), Task 1's guardWritable() inside the action() closure still
     * aborts it — the block is never touched.
     */
    #[Test]
    public function attempting_to_submit_the_view_modal_in_readonly_does_not_change_the_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'Нетронутый'],
        );

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id, 'readonly' => true])
            ->callAction('editBlockAction', data: ['content' => 'Взлом'], arguments: ['block' => $block->id, 'type' => 'content', 'isRoot' => true])
            ->assertStatus(403);

        $this->assertSame('Нетронутый', Block::find($block->id)->data['content']);
    }

    /**
     * Regression guard: Task 4 must not touch the writable path — editBlockAction() still opens
     * a normal, submittable, non-disabled form and a full open -> change -> save round trip
     * still persists (see also TgsCanvasEditTest for the broader end-to-end coverage).
     */
    #[Test]
    public function in_normal_mode_the_modal_is_enabled_and_editing_still_persists(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'Исходный'],
        );

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('editBlockAction', arguments: ['block' => $block->id, 'type' => 'content', 'isRoot' => true])
            ->assertSchemaComponentExists('content', checkComponentUsing: fn ($component): bool => ! $component->isDisabled());

        // @phpstan-ignore method.notFound (Livewire component method, ->instance() is typed as the base Component)
        $action = $component->instance()->getMountedActions()[0];
        $this->assertNotNull($action->getModalSubmitAction());
        $this->assertSame(
            __('tgs::filament.tgs.canvas.block_modal.edit', ['block' => 'Содержимое']),
            $action->getModalHeading(),
        );

        // Unmount before re-mounting the same action name via callAction() below — otherwise
        // Filament resolves it as a nested child action of the still-mounted one and throws
        // ActionNotResolvableException.
        $component->call('unmountAction');

        $component->callAction(
            'editBlockAction',
            data: ['content' => 'Новый текст'],
            arguments: ['block' => $block->id, 'type' => 'content', 'isRoot' => true],
        )->assertSuccessful();

        $this->assertStringContainsString('Новый текст', Block::find($block->id)->data['content']);
    }
}
