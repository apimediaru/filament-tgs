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

class InsertReferenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_references_an_existing_block_without_cloning_or_reowning(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        // Чужой блок (владеет settings-библиотека).
        $source = Block::updateOrCreate(['id' => 'LIB-1'], [
            'type' => 'content', 'data' => ['content' => 'shared'],
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
            'committed_at' => now(),
        ]);

        $placement = app(CanvasPersistence::class)->insertReference(
            $host, $host->containerType, $host->containerId, $host->slot, $source->id,
        );

        $this->assertNotNull($placement);
        // Тот же block_id размещён у нашего хоста, но владелец блока НЕ изменился.
        $this->assertTrue(BlockPlacement::query()
            ->where('container_id', $host->containerId)->where('slot', $host->slot)
            ->where('block_id', 'LIB-1')->exists());
        $this->assertSame('settings', Block::find('LIB-1')->owner_container_type);
        $this->assertSame(1, Block::query()->count()); // не склонировали
    }

    #[Test]
    public function referencing_a_missing_block_returns_null(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $this->assertNull(app(CanvasPersistence::class)->insertReference(
            $host, $host->containerType, $host->containerId, $host->slot, 'NOPE',
        ));
    }

    #[Test]
    public function referencing_a_soft_deleted_block_returns_null(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $trashed = Block::factory()->content(['content' => 'gone'])->withId('TRASHED-1')->create([
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
            'deleted_at' => now(), 'deleted_op_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ]);

        $this->assertNull(app(CanvasPersistence::class)->insertReference(
            $host, $host->containerType, $host->containerId, $host->slot, $trashed->id,
        ));
    }
}
