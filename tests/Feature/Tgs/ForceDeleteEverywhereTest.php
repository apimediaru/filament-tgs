<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockDeletion;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Вариант 1 удаления шаренного блока (Слайс C, Этап 3):
 * `CanvasPersistence::forceDeleteEverywhere()` — физически удаляет блок и ВСЕ его размещения
 * везде, во всех хостах, каскадно владеемых детей.
 */
class ForceDeleteEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private CanvasPersistence $persistence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persistence = app(CanvasPersistence::class);
    }

    #[Test]
    public function it_soft_deletes_the_block_and_removes_every_placement_referencing_it(): void
    {
        $owner = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');
        $consumerA = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');
        $consumerB = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');

        $block = $this->persistence->addBlock(
            $owner, $owner->containerType, $owner->containerId, $owner->slot, 'content', ['content' => 'shared'],
        );
        $this->persistence->insertReference($consumerA, $consumerA->containerType, $consumerA->containerId, $consumerA->slot, $block->id);
        $this->persistence->insertReference($consumerB, $consumerB->containerType, $consumerB->containerId, $consumerB->slot, $block->id);

        $this->assertSame(3, BlockPlacement::query()->where('block_id', $block->id)->count());

        $this->persistence->forceDeleteEverywhere($block->id);

        $deleted = Block::find($block->id);
        $this->assertNotNull($deleted, 'soft-deleted block row must still exist');
        $this->assertNotNull($deleted->deleted_at);
        $this->assertNotNull($deleted->deleted_op_id);
        $this->assertDatabaseHas('tgs_block_deletions', [
            'block_id' => $block->id,
            'transaction_id' => $deleted->deleted_op_id,
        ]);
        // Every placement, everywhere — this is the "force" variant's whole point, unaffected
        // by the delete becoming soft: all three references are gone regardless.
        $this->assertSame(0, BlockPlacement::query()->where('block_id', $block->id)->count());
    }

    #[Test]
    public function it_cascades_owned_children_of_the_deleted_root(): void
    {
        $owner = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');
        $consumer = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');

        $container = $this->persistence->addBlock(
            $owner, $owner->containerType, $owner->containerId, $owner->slot, 'column', [],
        );
        $child = $this->persistence->addBlock(
            $owner, 'block', $container->id, 'content', 'content', ['content' => 'child'],
        );
        $this->persistence->insertReference($consumer, $consumer->containerType, $consumer->containerId, $consumer->slot, $container->id);

        $this->persistence->forceDeleteEverywhere($container->id);

        $deletedContainer = Block::find($container->id);
        $deletedChild = Block::find($child->id);
        $this->assertNotNull($deletedContainer, 'container block row must still exist');
        $this->assertNotNull($deletedChild, 'child block row must still exist');
        $this->assertNotNull($deletedContainer->deleted_at);
        $this->assertNotNull($deletedChild->deleted_at);
        $this->assertSame($deletedContainer->deleted_op_id, $deletedChild->deleted_op_id);
        $this->assertSame(1, BlockDeletion::query()->where('transaction_id', $deletedContainer->deleted_op_id)->count());
        $this->assertSame(0, BlockPlacement::query()->where('block_id', $container->id)->count());
        // Structural (block=container)-scoped edge for the child is preserved for restore
        // (Слайс B, #6) — only the ROOT's own external placements (asserted above) are gone.
        $this->assertSame(1, BlockPlacement::query()->where('container_type', 'block')->where('container_id', $container->id)->where('block_id', $child->id)->count());
    }

    #[Test]
    public function it_does_not_delete_a_child_that_is_referenced_independently_elsewhere(): void
    {
        $owner = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');
        $consumer = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');

        $container = $this->persistence->addBlock(
            $owner, $owner->containerType, $owner->containerId, $owner->slot, 'column', [],
        );
        $child = $this->persistence->addBlock(
            $owner, 'block', $container->id, 'content', 'content', ['content' => 'child'],
        );

        // Ребёнок используется и отдельно, вне удаляемого поддерева — должен пережить каскад,
        // переоткрепившись на orphan, а не быть удалённым вместе с родителем.
        $this->persistence->insertReference($consumer, $consumer->containerType, $consumer->containerId, $consumer->slot, $child->id);

        $this->persistence->forceDeleteEverywhere($container->id);

        $this->assertNotNull(Block::find($container->id)?->deleted_at);
        $survivingChild = Block::find($child->id);
        $this->assertNotNull($survivingChild);
        $this->assertNull($survivingChild->deleted_at, 'child still referenced elsewhere must not be soft-deleted');
        $this->assertSame('orphan', $survivingChild->owner_container_type);
    }

    #[Test]
    public function it_is_a_no_op_when_the_block_does_not_exist(): void
    {
        // Не должно бросать — симметрично остальным мутирующим методам, работающим с
        // несуществующим id (например deleteBlock()).
        $this->persistence->forceDeleteEverywhere('NON-EXISTENT-ID');

        $this->assertNull(Block::find('NON-EXISTENT-ID'));
    }
}
