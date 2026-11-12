<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Task 1 (плейбук "линки как ссылки"): "чужой" (owner != host) — техническая деталь, не
 * пользовательская. `referenceSourceLabel` даёт источник-осведомлённый лейбл вместо
 * generic "чужой блок": владелец = library-хост → «Из библиотеки», иначе — нейтральное
 * «Связанный».
 */
class ReferenceBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function libraryHost(): HostDescriptor
    {
        return Canvas::named(TgsNamedCanvas::BlockLibrary)->hostDescriptor();
    }

    #[Test]
    public function reference_source_label_is_from_library_for_a_block_owned_by_the_library_host(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $libHost = $this->libraryHost();

        $libBlock = Block::updateOrCreate(['id' => 'LIB-BADGE-1'], [
            'type' => 'content', 'data' => ['content' => 'reusable'],
            'owner_container_type' => $libHost->containerType, 'owner_container_id' => $libHost->containerId,
        ]);
        $libBlock->forceFill(['committed_at' => now()])->save();

        app(CanvasPersistence::class)->insertReference(
            $host, $host->containerType, $host->containerId, $host->slot, $libBlock->id,
        );

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id]);

        /** @var TgsCanvas $instance */
        $instance = $component->instance();

        $this->assertSame(__('tgs::filament.tgs.reference.from_library'), $instance->referenceSourceLabel($libBlock->id));
    }

    #[Test]
    public function reference_source_label_is_linked_for_a_block_owned_by_a_non_library_foreign_host(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $foreign = Block::updateOrCreate(['id' => 'FOR-BADGE-1'], [
            'type' => 'content', 'data' => ['content' => 'shared'],
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
        ]);
        $foreign->forceFill(['committed_at' => now()])->save();

        app(CanvasPersistence::class)->insertReference(
            $host, $host->containerType, $host->containerId, $host->slot, $foreign->id,
        );

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id]);

        /** @var TgsCanvas $instance */
        $instance = $component->instance();

        $this->assertSame(__('tgs::filament.tgs.reference.linked'), $instance->referenceSourceLabel($foreign->id));
    }

    #[Test]
    public function the_origin_placement_of_a_reused_block_is_not_flagged_as_a_reference(): void
    {
        // insertFromToken()'s "as reference" option lets a host paste a block's own id back
        // into a slot on the SAME page it already owns — isForeignBlock() (ownership-based)
        // can never flag either placement, since the block's owner IS this host both times.
        // isReferencePlacement() is placement-identity-based instead: the FIRST-created
        // placement for a block_id is "the origin" and must never be badged — only a later
        // placement of the same block is a "reference" to it.
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $svc = app(CanvasPersistence::class);

        $block = $svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'shared']);
        $svc->insertReference($host, $host->containerType, $host->containerId, $host->slot, $block->id);

        $originPlacementId = BlockPlacement::query()->where('block_id', $block->id)->orderBy('id')->value('id');

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id]);

        /** @var TgsCanvas $instance */
        $instance = $component->instance();

        $this->assertFalse($instance->isReferencePlacement($originPlacementId, $block->id));
        $this->assertFalse($instance->isForeignBlock($block->id), 'the block is still owned by this host, not foreign');
    }

    #[Test]
    public function a_later_placement_of_a_reused_block_is_flagged_as_a_reference(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $svc = app(CanvasPersistence::class);

        $block = $svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'shared']);
        $svc->insertReference($host, $host->containerType, $host->containerId, $host->slot, $block->id);

        $referencePlacementId = BlockPlacement::query()->where('block_id', $block->id)->orderByDesc('id')->value('id');

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id]);

        /** @var TgsCanvas $instance */
        $instance = $component->instance();

        $this->assertTrue($instance->isReferencePlacement($referencePlacementId, $block->id));
    }

    #[Test]
    public function is_reference_placement_is_false_for_a_block_placed_only_once(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $svc = app(CanvasPersistence::class);

        $block = $svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'solo']);
        $placementId = BlockPlacement::query()->where('block_id', $block->id)->value('id');

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id]);

        /** @var TgsCanvas $instance */
        $instance = $component->instance();

        $this->assertFalse($instance->isReferencePlacement($placementId, $block->id));
    }

    #[Test]
    public function shared_badge_renders_only_for_the_later_placement_not_the_origin(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $svc = app(CanvasPersistence::class);

        $block = $svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'shared-ui']);
        $svc->insertReference($host, $host->containerType, $host->containerId, $host->slot, $block->id);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id]);
        $component->assertSuccessful();
        $html = $component->html();

        $this->assertStringContainsString('data-testid="shared-badge"', $html);
        // Compact chip (node header priority/compaction plan, Task A): the "По ссылке" label
        // moved into the chip's tooltip, replaced inline by an icon + place count.
        $this->assertStringContainsString(
            __('tgs::filament.tgs.reference.shared_tooltip'),
            html_entity_decode($html, ENT_QUOTES),
        );
        $this->assertSame(1, substr_count($html, 'data-testid="shared-badge"'), 'only the later placement gets the badge, not the origin');
    }
}
