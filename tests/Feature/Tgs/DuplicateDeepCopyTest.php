<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class DuplicateDeepCopyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deep_copies_a_container_with_children_using_new_ids(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        $col = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'double_column', []);
        $childL = $p->addBlock($host, 'block', $col->id, 'left', 'content', ['content' => 'L']);
        $childR = $p->addBlock($host, 'block', $col->id, 'right', 'content', ['content' => 'R']);

        $clone = $p->duplicateBlock($host, $host->containerType, $host->containerId, $host->slot, $col->id);

        // Новый корень, отличный id.
        $this->assertNotNull($clone);
        $this->assertNotSame($col->id, $clone->id);
        $this->assertSame('double_column', $clone->type);

        // Дети склонированы с новыми id, но той же структурой/данными.
        $cloneLeft = BlockPlacement::query()->where('container_id', $clone->id)->where('slot', 'left')->value('block_id');
        $this->assertNotNull($cloneLeft);
        $this->assertNotSame($childL->id, $cloneLeft);
        $this->assertSame('L', Block::find($cloneLeft)->data['content']);
        $this->assertTrue(BlockPlacement::query()->where('container_id', $clone->id)->where('slot', 'right')->exists());
    }

    #[Test]
    public function the_copy_is_inserted_right_after_the_source(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        $a = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'A']);
        $b = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'B']);

        $clone = $p->duplicateBlock($host, $host->containerType, $host->containerId, $host->slot, $a->id);

        $order = BlockPlacement::query()
            ->where('container_type', $host->containerType)->where('container_id', $host->containerId)->where('slot', $host->slot)
            ->orderBy('rank')->pluck('block_id')->all();

        $this->assertSame([$a->id, $clone->id, $b->id], $order);
    }

    #[Test]
    public function a_synced_foreign_block_in_the_subtree_is_referenced_not_cloned(): void
    {
        $demo = TgsDemo::factory()->create();
        $otherDemo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        $col = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'double_column', []);

        // Чужой (synced) блок, владеемый другим хостом, размещённый в слоте нашего контейнера.
        $foreign = Block::updateOrCreate(['id' => 'FOREIGN-SYNC'], [
            'type' => 'content', 'data' => ['content' => 'shared'],
            'owner_container_type' => $host->containerType, 'owner_container_id' => (string) $otherDemo->id,
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'block', 'container_id' => $col->id, 'slot' => 'left', 'rank' => '000000', 'block_id' => $foreign->id,
        ]);

        $clone = $p->duplicateBlock($host, $host->containerType, $host->containerId, $host->slot, $col->id);

        // В копии left ссылается на ТОТ ЖЕ foreign-блок (синхрон сохранён), а не на клон.
        $cloneLeft = BlockPlacement::query()->where('container_id', $clone->id)->where('slot', 'left')->value('block_id');
        $this->assertSame($foreign->id, $cloneLeft);
    }

    #[Test]
    public function duplicating_a_foreign_root_block_returns_null_and_creates_no_placement(): void
    {
        $demo = TgsDemo::factory()->create();
        $otherDemo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        // Чужой (не наш и не orphan) блок, размещённый напрямую в слоте хоста (не как child).
        $foreign = Block::updateOrCreate(['id' => 'FOREIGN-ROOT'], [
            'type' => 'content', 'data' => ['content' => 'shared'],
            'owner_container_type' => $host->containerType, 'owner_container_id' => (string) $otherDemo->id,
        ]);
        BlockPlacement::forceCreate([
            'container_type' => $host->containerType, 'container_id' => $host->containerId, 'slot' => $host->slot,
            'rank' => '000000', 'block_id' => $foreign->id,
        ]);

        $countBefore = BlockPlacement::query()
            ->where('container_type', $host->containerType)->where('container_id', $host->containerId)->where('slot', $host->slot)
            ->count();

        $clone = $p->duplicateBlock($host, $host->containerType, $host->containerId, $host->slot, $foreign->id);

        $this->assertNull($clone);

        $countAfter = BlockPlacement::query()
            ->where('container_type', $host->containerType)->where('container_id', $host->containerId)->where('slot', $host->slot)
            ->count();
        $this->assertSame($countBefore, $countAfter);
    }
}
