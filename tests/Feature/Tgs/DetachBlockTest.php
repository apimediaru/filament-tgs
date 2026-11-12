<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\BlockVersion;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class DetachBlockTest extends TestCase
{
    use RefreshDatabase;

    private function referencedForeign(TgsDemo $demo, HostDescriptor $host): Block
    {
        $foreign = Block::updateOrCreate(['id' => 'FOR-1'], [
            'type' => 'content', 'data' => ['content' => 'shared'],
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
            'committed_at' => now(),
        ]);
        app(CanvasPersistence::class)->insertReference($host, $host->containerType, $host->containerId, $host->slot, $foreign->id);

        return $foreign;
    }

    #[Test]
    public function detaching_replaces_the_reference_with_an_owned_clone(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $foreign = $this->referencedForeign($demo, $host);

        $clone = app(CanvasPersistence::class)->detachBlock(
            $host, $host->containerType, $host->containerId, $host->slot, $foreign->id,
        );

        $this->assertNotNull($clone);
        $this->assertNotSame($foreign->id, $clone->id);
        $this->assertSame($host->containerType, $clone->owner_container_type); // теперь наш
        // Placement указывает на клон, не на чужой.
        $placed = BlockPlacement::query()->where('container_id', $host->containerId)->where('slot', $host->slot)->value('block_id');
        $this->assertSame($clone->id, $placed);
        // Оригинал в библиотеке цел.
        $this->assertNotNull(Block::find('FOR-1'));
    }

    #[Test]
    public function detaching_a_placement_that_does_not_exist_returns_null(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $this->referencedForeign($demo, $host);

        $clone = app(CanvasPersistence::class)->detachBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'NOT-PLACED-HERE',
        );

        $this->assertNull($clone);
    }

    /**
     * Полноценный сценарий вложенных ссылок: сам детачируемый блок — чужой для хоста
     * (принадлежит otherDemo), а внутри него — вложенная ссылка на ещё один чужой блок
     * (из settings-библиотеки). Проверяем оба режима detachNested на одном и том же
     * дереве (переразмещая исходную ссылку между вызовами, т.к. первый detach уже
     * заменяет placement на клон).
     */
    #[Test]
    public function nested_references_stay_synced_unless_detach_nested(): void
    {
        $demo = TgsDemo::factory()->create();
        $otherDemo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $otherHost = HostDescriptor::forModelSlot($otherDemo, 'content');
        $p = app(CanvasPersistence::class);

        // Контейнер владеет otherDemo — чужой для $host.
        $container = $p->addBlock($otherHost, $otherHost->containerType, $otherHost->containerId, $otherHost->slot, 'column', []);

        // Внутри контейнера — вложенная ссылка на блок из settings-библиотеки (чужой и для otherDemo).
        $inner = Block::updateOrCreate(['id' => 'INNER-REF'], [
            'type' => 'content', 'data' => [], 'owner_container_type' => 'settings',
            'owner_container_id' => 'block_library.value', 'committed_at' => now(),
        ]);
        $p->insertReference($otherHost, 'block', $container->id, 'content', $inner->id);

        // Хост ссылается на этот контейнер целиком.
        $p->insertReference($host, $host->containerType, $host->containerId, $host->slot, $container->id);

        $this->assertTrue($p->subtreeHasReferences($host, $container->id));

        // --- detachNested=false: вложенная ссылка остаётся ссылкой на тот же id. ---
        $cloneShallow = $p->detachBlock(
            $host, $host->containerType, $host->containerId, $host->slot, $container->id, detachNested: false,
        );

        $this->assertNotNull($cloneShallow);
        $this->assertNotSame($container->id, $cloneShallow->id);
        $this->assertSame($host->containerType, $cloneShallow->owner_container_type);
        $this->assertSame($host->containerId, $cloneShallow->owner_container_id);

        $shallowChild = BlockPlacement::query()
            ->where('container_type', 'block')->where('container_id', $cloneShallow->id)->where('slot', 'content')
            ->value('block_id');
        $this->assertSame('INNER-REF', $shallowChild); // всё ещё та же ссылка

        // Placement хоста теперь указывает на клон, а не на container — переставим
        // ссылку обратно на исходный container для второго прогона.
        $p->insertReference($host, $host->containerType, $host->containerId, $host->slot, $container->id);

        // --- detachNested=true: вложенная ссылка тоже становится собственным клоном. ---
        $cloneDeep = $p->detachBlock(
            $host, $host->containerType, $host->containerId, $host->slot, $container->id, detachNested: true,
        );

        $this->assertNotNull($cloneDeep);
        $this->assertNotSame($container->id, $cloneDeep->id);

        $deepChild = BlockPlacement::query()
            ->where('container_type', 'block')->where('container_id', $cloneDeep->id)->where('slot', 'content')
            ->value('block_id');
        $this->assertNotNull($deepChild);
        $this->assertNotSame('INNER-REF', $deepChild); // теперь отдельный клон, новый id
        $this->assertSame($host->containerType, Block::find($deepChild)->owner_container_type);
        $this->assertSame($host->containerId, Block::find($deepChild)->owner_container_id);

        // Оригинальная библиотечная ссылка не тронута.
        $this->assertNotNull(Block::find('INNER-REF'));
    }

    /**
     * Вариант 3 удаления шаренного блока (Слайс C, Этап 3): detachBlock() создаёт независимую
     * копию, но она должна нести с собой историю оригинала — иначе detach выглядит как "начать
     * с нуля" вместо "перестать делить блок, сохранив всё как было".
     */
    #[Test]
    public function the_clone_inherits_a_copy_of_the_original_blocks_version_history(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $foreign = $this->referencedForeign($demo, $host);

        BlockVersion::create(['block_id' => $foreign->id, 'data' => ['content' => 'v1'], 'created_by' => null]);
        BlockVersion::create(['block_id' => $foreign->id, 'data' => ['content' => 'v2'], 'created_by' => null]);

        $clone = app(CanvasPersistence::class)->detachBlock(
            $host, $host->containerType, $host->containerId, $host->slot, $foreign->id,
        );

        $this->assertNotNull($clone);

        $originalVersions = BlockVersion::query()->where('block_id', $foreign->id)->orderBy('id')->pluck('data')->all();
        $cloneVersions = BlockVersion::query()->where('block_id', $clone->id)->orderBy('id')->pluck('data')->all();

        // Оригинал сохраняет свои версии нетронутыми...
        $this->assertSame([['content' => 'v1'], ['content' => 'v2']], $originalVersions);
        // ...а клон получает СВОЮ копию, с теми же данными, но собственными строками/id.
        $this->assertSame([['content' => 'v1'], ['content' => 'v2']], $cloneVersions);
        $this->assertNotSame(
            BlockVersion::query()->where('block_id', $foreign->id)->pluck('id')->all(),
            BlockVersion::query()->where('block_id', $clone->id)->pluck('id')->all(),
        );
    }

    #[Test]
    public function subtree_has_references_is_false_when_the_whole_subtree_is_owned(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        $container = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $p->addBlock($host, 'block', $container->id, 'content', 'content', ['content' => 'owned']);

        $this->assertFalse($p->subtreeHasReferences($host, $container->id));
    }
}
