<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockMedia;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class CanvasPersistencePurgeSlotTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function purging_a_slot_removes_owned_blocks_and_their_placements(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $persistence = app(CanvasPersistence::class);

        $parent = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'double_column', []);
        $child = $persistence->addBlock($host, 'block', $parent->id, 'left', 'content', []);

        $persistence->purgeSlot($host, $host->containerType, $host->containerId, $host->slot);

        $this->assertNull(Block::find($parent->id));
        $this->assertNull(Block::find($child->id));
        $this->assertSame(0, BlockPlacement::query()->where('container_id', (string) $demo->id)->count());
        $this->assertSame(0, BlockPlacement::query()->where('container_id', $parent->id)->count());
    }

    #[Test]
    public function purging_a_slot_does_not_delete_a_foreign_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $otherDemo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        // Блок, владеемый ДРУГИМ хостом, но размещённый в слоте нашего.
        $foreign = Block::updateOrCreate(['id' => 'FOREIGN-PURGE-TEST'], [
            'type' => 'content',
            'data' => [],
            'owner_container_type' => $host->containerType,
            'owner_container_id' => (string) $otherDemo->id,
        ]);
        BlockPlacement::forceCreate([
            'container_type' => $host->containerType,
            'container_id' => $host->containerId,
            'slot' => $host->slot,
            'rank' => '000000',
            'block_id' => $foreign->id,
        ]);

        app(CanvasPersistence::class)->purgeSlot($host, $host->containerType, $host->containerId, $host->slot);

        // Ссылка снята, сам чужой блок жив.
        $this->assertNotNull(Block::find($foreign->id));
        $this->assertSame(0, BlockPlacement::query()->where('container_id', (string) $demo->id)->count());
    }

    #[Test]
    public function purging_the_owning_hosts_slot_reassigns_a_still_referenced_block_to_orphan_instead_of_dangling(): void
    {
        // Баг: владелец хоста A удаляется/пурджит слот, блок пережил его (ещё используется
        // хостом B через ссылку) — раньше owner_container_* так и оставался указывать на
        // ушедшего владельца A, и блок становился "чужим" НАВСЕГДА для любого живого хоста,
        // включая B — единственного, кто им реально пользуется.
        $owner = TgsDemo::factory()->create();
        $consumer = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $consumerHost = HostDescriptor::forModelSlot($consumer, 'content');
        $persistence = app(CanvasPersistence::class);

        $block = $persistence->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'content', []);
        $persistence->insertReference($consumerHost, $consumerHost->containerType, $consumerHost->containerId, $consumerHost->slot, $block->id);

        $persistence->purgeSlot($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot);

        $reloaded = Block::find($block->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('orphan', $reloaded->owner_container_type);
        $this->assertSame(1, BlockPlacement::query()->where('block_id', $block->id)->count());
    }

    #[Test]
    public function deleting_ones_own_placement_reassigns_a_still_referenced_block_to_orphan_without_deleting_the_owner_host(): void
    {
        // Тот же дефект без удаления хоста целиком: владелец просто убирает блок со своей
        // страницы (deleteBlock — "Удалить"/"Убрать со страницы" на СВОЁМ блоке), но блок
        // пережил это удаление, потому что кем-то ещё переиспользован.
        $owner = TgsDemo::factory()->create();
        $consumer = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $consumerHost = HostDescriptor::forModelSlot($consumer, 'content');
        $persistence = app(CanvasPersistence::class);

        $block = $persistence->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'content', []);
        $persistence->insertReference($consumerHost, $consumerHost->containerType, $consumerHost->containerId, $consumerHost->slot, $block->id);

        $persistence->deleteBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, $block->id);

        $reloaded = Block::find($block->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('orphan', $reloaded->owner_container_type);
        $this->assertNotNull(TgsDemo::find($owner->id)); // хост-владелец жив, только сам не тронул этот блок больше
    }

    #[Test]
    public function purging_reassigns_a_nested_owned_child_that_is_also_referenced_elsewhere(): void
    {
        // Тот же класс бага на уровень глубже: владеемый ребёнок ВНУТРИ удаляемого
        // поддерева, который отдельно от родителя ещё переиспользован другим хостом.
        $owner = TgsDemo::factory()->create();
        $consumer = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $consumerHost = HostDescriptor::forModelSlot($consumer, 'content');
        $persistence = app(CanvasPersistence::class);

        $container = $persistence->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'column', []);
        $child = $persistence->addBlock($ownerHost, 'block', $container->id, 'content', 'content', []);
        $persistence->insertReference($consumerHost, $consumerHost->containerType, $consumerHost->containerId, $consumerHost->slot, $child->id);

        $persistence->purgeSlot($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot);

        $this->assertNull(Block::find($container->id));
        $reloadedChild = Block::find($child->id);
        $this->assertNotNull($reloadedChild);
        $this->assertSame('orphan', $reloadedChild->owner_container_type);
        $this->assertSame(1, BlockPlacement::query()->where('block_id', $child->id)->count());
    }

    #[Test]
    public function purging_a_slot_cleans_media_of_a_block_nested_two_levels_deep(): void
    {
        // Срез 0 (docs/features/tgs.md) уже чинил mass-delete-в-обход-событий-модели на
        // КОРНЕ каскада; здесь — что то же самое не regressии на ВЛОЖЕННОМ уровне: медиа
        // очищается по каждому узлу BFS-очереди, а не только по корню поддерева.
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $persistence = app(CanvasPersistence::class);

        $container = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $child = $persistence->addBlock($host, 'block', $container->id, 'content', 'picture', ['uuid' => 'nested-uuid']);
        $child->addMedia(UploadedFile::fake()->image('nested.jpg'))
            ->withCustomProperties(['uuid' => 'nested-uuid'])
            ->toMediaCollection('content_builder');

        $persistence->purgeSlot($host, $host->containerType, $host->containerId, $host->slot);

        $this->assertNull(Block::find($child->id));
        $this->assertDatabaseMissing(BlockMedia::class, ['custom_properties->uuid' => 'nested-uuid']);
    }

    #[Test]
    public function purging_the_owning_hosts_slot_preserves_media_of_a_block_reassigned_to_orphan(): void
    {
        // Дополняет orphan-реотдачу владения (см. соседний тест выше): блок, который
        // ПЕРЕЖИЛ владельца и потому не удаляется, а переоткрепляется на orphan, не должен
        // случайно потерять медиа — переоткрепление это чистый UPDATE owner_container_*,
        // но стоит явно закрепить тестом, что оно и остаётся только UPDATE'ом.
        $owner = TgsDemo::factory()->create();
        $consumer = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $consumerHost = HostDescriptor::forModelSlot($consumer, 'content');
        $persistence = app(CanvasPersistence::class);

        $block = $persistence->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'picture', ['uuid' => 'orphan-media-uuid']);
        $block->addMedia(UploadedFile::fake()->image('orphan.jpg'))
            ->withCustomProperties(['uuid' => 'orphan-media-uuid'])
            ->toMediaCollection('content_builder');
        $persistence->insertReference($consumerHost, $consumerHost->containerType, $consumerHost->containerId, $consumerHost->slot, $block->id);

        $persistence->purgeSlot($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot);

        $reloaded = Block::find($block->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('orphan', $reloaded->owner_container_type);
        $this->assertCount(1, $reloaded->getMedia('content_builder'));
        $this->assertDatabaseHas(BlockMedia::class, ['custom_properties->uuid' => 'orphan-media-uuid']);
    }
}
