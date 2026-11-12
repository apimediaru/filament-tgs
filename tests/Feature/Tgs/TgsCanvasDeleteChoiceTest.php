<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Слайс C (Этап 3): три варианта удаления шаренного блока на `deleteBlockAction()`. Модалка
 * показывает Radio-выбор ТОЛЬКО когда блок используется больше чем в одном месте
 * (`usagePlacesCount() > 1`) — единичное размещение остаётся простым удалением без выбора
 * (см. TgsCanvasDeleteActionTest, тот сценарий не должен ломаться).
 */
class TgsCanvasDeleteChoiceTest extends TestCase
{
    use RefreshDatabase;

    private CanvasPersistence $persistence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persistence = app(CanvasPersistence::class);
    }

    #[Test]
    public function the_choice_field_is_hidden_when_the_block_has_only_one_usage_place(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = $this->persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', []);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('deleteBlockAction', arguments: ['block' => $block->id, 'parent' => null, 'slot' => null])
            ->assertMountedActionModalDontSee(__('tgs::filament.tgs.canvas.delete.choice.field_label'));
    }

    #[Test]
    public function the_choice_field_is_shown_with_both_options_when_the_block_has_multiple_usage_places(): void
    {
        $owner = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($owner, 'content');
        $consumer = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');

        $block = $this->persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', []);
        $this->persistence->insertReference($consumer, $consumer->containerType, $consumer->containerId, $consumer->slot, $block->id);

        Livewire::test(TgsCanvas::class, ['canvasId' => $owner->canvas('content')->id])
            ->mountAction('deleteBlockAction', arguments: ['block' => $block->id, 'parent' => null, 'slot' => null])
            ->assertMountedActionModalSee(__('tgs::filament.tgs.canvas.delete.choice.field_label'))
            ->assertMountedActionModalSee(__('tgs::filament.tgs.canvas.delete.choice.unlink.label'))
            ->assertMountedActionModalSee(__('tgs::filament.tgs.canvas.delete.choice.force.label'));
    }

    #[Test]
    public function choosing_force_deletes_the_block_and_every_placement_everywhere(): void
    {
        $owner = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($owner, 'content');
        $consumer = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');

        $block = $this->persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', []);
        $this->persistence->insertReference($consumer, $consumer->containerType, $consumer->containerId, $consumer->slot, $block->id);

        Livewire::test(TgsCanvas::class, ['canvasId' => $owner->canvas('content')->id])
            ->callAction('deleteBlockAction', data: ['deleteChoice' => 'force'], arguments: ['block' => $block->id, 'parent' => null, 'slot' => null])
            ->assertSuccessful();

        $this->assertNotNull(Block::find($block->id)?->deleted_at, 'block must be soft-deleted, not gone');
        $this->assertSame(0, BlockPlacement::query()->where('block_id', $block->id)->count());
    }

    #[Test]
    public function choosing_unlink_keeps_today_s_behavior_only_this_placement_is_removed(): void
    {
        $owner = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($owner, 'content');
        $consumer = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');

        $block = $this->persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', []);
        $this->persistence->insertReference($consumer, $consumer->containerType, $consumer->containerId, $consumer->slot, $block->id);

        Livewire::test(TgsCanvas::class, ['canvasId' => $owner->canvas('content')->id])
            ->callAction('deleteBlockAction', data: ['deleteChoice' => 'unlink'], arguments: ['block' => $block->id, 'parent' => null, 'slot' => null])
            ->assertSuccessful();

        // Размещение хоста снято...
        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->where('block_id', $block->id)
            ->count());

        // ...но блок пережил (используется у consumer) — не удалён физически.
        $this->assertNotNull(Block::find($block->id));
        $this->assertTrue(BlockPlacement::query()
            ->where('container_type', $consumer->containerType)
            ->where('container_id', $consumer->containerId)
            ->where('slot', $consumer->slot)
            ->where('block_id', $block->id)
            ->exists());
    }

    #[Test]
    public function the_choice_field_is_hidden_for_a_foreign_shared_block(): void
    {
        [$owner, $consumer, $block] = $this->blockSharedFromOwnerToConsumer();

        Livewire::test(TgsCanvas::class, ['canvasId' => $consumer->canvas('content')->id])
            ->mountAction('deleteBlockAction', arguments: ['block' => $block->id, 'parent' => null, 'slot' => null])
            ->assertMountedActionModalDontSee(__('tgs::filament.tgs.canvas.delete.choice.field_label'))
            ->assertMountedActionModalSee(__('tgs::filament.tgs.canvas.delete.unlink_heading'));
    }

    #[Test]
    public function deleting_a_foreign_shared_block_only_unlinks_it_from_the_consumer(): void
    {
        // No deleteChoice field for a foreign block — the form can't even carry 'force'
        // (Livewire rejects the unknown property), and the server derives 'unlink' itself.
        [$owner, $consumer, $block] = $this->blockSharedFromOwnerToConsumer();

        Livewire::test(TgsCanvas::class, ['canvasId' => $consumer->canvas('content')->id])
            ->callAction('deleteBlockAction', arguments: ['block' => $block->id, 'parent' => null, 'slot' => null])
            ->assertSuccessful();

        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $this->assertNull(Block::find($block->id)?->deleted_at, 'a foreign block must never be deleted everywhere from a consumer canvas');
        $this->assertTrue(BlockPlacement::query()
            ->where('container_type', $ownerHost->containerType)
            ->where('container_id', $ownerHost->containerId)
            ->where('block_id', $block->id)
            ->exists());
        $this->assertFalse(BlockPlacement::query()
            ->where('container_id', (string) $consumer->id)
            ->where('block_id', $block->id)
            ->exists(), 'the consumer placement itself is removed');
    }

    #[Test]
    public function force_on_a_block_not_placed_on_this_canvas_is_forbidden(): void
    {
        // An orphan counts as «own» for every canvas (blockBelongsToHost), so the choice is shown —
        // but a canvas the block isn't even placed on must not be able to delete it everywhere.
        $first = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');
        $second = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');
        $orphan = Block::factory()->content()->create(Block::unclaimedOwner());
        $this->persistence->insertReference($first, $first->containerType, $first->containerId, $first->slot, $orphan->id);
        $this->persistence->insertReference($second, $second->containerType, $second->containerId, $second->slot, $orphan->id);

        $unrelated = TgsDemo::factory()->create();

        Livewire::test(TgsCanvas::class, ['canvasId' => $unrelated->canvas('content')->id])
            ->callAction('deleteBlockAction', data: ['deleteChoice' => 'force'], arguments: ['block' => $orphan->id, 'parent' => null, 'slot' => null])
            ->assertForbidden();

        $this->assertNull(Block::find($orphan->id)?->deleted_at);
        $this->assertSame(2, BlockPlacement::query()->where('block_id', $orphan->id)->count());
    }

    /** @return array{0: TgsDemo, 1: TgsDemo, 2: Block} */
    private function blockSharedFromOwnerToConsumer(): array
    {
        $owner = TgsDemo::factory()->create();
        $consumer = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $consumerHost = HostDescriptor::forModelSlot($consumer, 'content');

        $block = $this->persistence->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'content', []);
        $this->persistence->insertReference($consumerHost, $consumerHost->containerType, $consumerHost->containerId, $consumerHost->slot, $block->id);

        return [$owner, $consumer, $block];
    }

    // "detach" (независимая копия на этом же месте) больше не вариант в этой модалке — по
    // коду он ничего не удаляет (сам detachBlock() и его wiring через отдельный
    // detachBlockAction() остаются полностью, покрыты DetachBlockTest); удалять запрос
    // пользователя не решает, и остался тем, чем и был — отдельным действием на узле, не в
    // choice-модалке удаления.
}
