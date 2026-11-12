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

class CopyBlockIntoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_fully_clones_a_foreign_source_into_the_target_host(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        // Источник владеет библиотека (чужой для страницы), с владеемым ребёнком.
        $lib = Block::updateOrCreate(['id' => 'LIB-COL'], [
            'type' => 'column', 'data' => [], 'owner_container_type' => 'settings',
            'owner_container_id' => 'block_library.value', 'committed_at' => now(),
        ]);
        $libChild = Block::updateOrCreate(['id' => 'LIB-CHILD'], [
            'type' => 'content', 'data' => ['content' => 'X'], 'owner_container_type' => 'settings',
            'owner_container_id' => 'block_library.value', 'committed_at' => now(),
        ]);
        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => 'LIB-COL', 'slot' => 'content', 'rank' => '000000', 'block_id' => 'LIB-CHILD']);

        $clone = app(CanvasPersistence::class)->copyBlockInto(
            $host, $host->containerType, $host->containerId, $host->slot, 'LIB-COL',
        );

        $this->assertNotNull($clone);
        $this->assertNotSame('LIB-COL', $clone->id);
        $this->assertSame($host->containerType, $clone->owner_container_type); // владеет страница
        // Копия сразу committed (не черновик) — иначе попадёт под Block::prunable().
        $this->assertNotNull($clone->committed_at);
        // Ребёнок тоже склонирован и владеется страницей (полная копия, не ссылка).
        $childCloneId = BlockPlacement::query()->where('container_id', $clone->id)->where('slot', 'content')->value('block_id');
        $this->assertNotSame('LIB-CHILD', $childCloneId);
        $childClone = Block::find($childCloneId);
        $this->assertSame($host->containerType, $childClone->owner_container_type);
        // committed_at проставляется всему поддереву, не только корню.
        $this->assertNotNull($childClone->committed_at);
        // Библиотечный оригинал цел.
        $this->assertNotNull(Block::find('LIB-COL'));
    }

    #[Test]
    public function it_returns_null_when_source_block_does_not_exist(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $clone = app(CanvasPersistence::class)->copyBlockInto(
            $host, $host->containerType, $host->containerId, $host->slot, 'NOPE',
        );

        $this->assertNull($clone);
    }
}
