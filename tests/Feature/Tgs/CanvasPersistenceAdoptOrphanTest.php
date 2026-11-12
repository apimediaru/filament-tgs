<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class CanvasPersistenceAdoptOrphanTest extends TestCase
{
    use RefreshDatabase;

    protected CanvasPersistence $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CanvasPersistence::class);
    }

    private function libraryHost(): HostDescriptor
    {
        return Canvas::named(TgsNamedCanvas::BlockLibrary)->hostDescriptor();
    }

    #[Test]
    public function it_places_the_orphan_at_the_end_of_the_library_root(): void
    {
        $block = Block::factory()->orphan()->create();

        $placement = $this->svc->adoptOrphanIntoLibrary($block->id);

        $this->assertNotNull($placement);
        $host = $this->libraryHost();
        $this->assertSame(1, BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->where('block_id', $block->id)
            ->count());
    }

    #[Test]
    public function it_reassigns_ownership_to_the_library(): void
    {
        $block = Block::factory()->orphan()->create();

        $this->svc->adoptOrphanIntoLibrary($block->id);

        $block->refresh();
        $host = $this->libraryHost();
        $this->assertSame($host->containerType, $block->owner_container_type);
        $this->assertSame($host->containerId, $block->owner_container_id);
    }

    #[Test]
    public function it_does_not_touch_existing_placements_the_block_already_had(): void
    {
        $block = Block::factory()->orphan()->create();
        $demo = TgsDemo::factory()->create();
        $pageHost = HostDescriptor::forModelSlot($demo, 'content');
        $this->svc->insertReference($pageHost, $pageHost->containerType, $pageHost->containerId, $pageHost->slot, $block->id);

        $countBefore = BlockPlacement::query()->where('block_id', $block->id)->count();

        $this->svc->adoptOrphanIntoLibrary($block->id);

        $this->assertSame($countBefore + 1, BlockPlacement::query()->where('block_id', $block->id)->count());
        $this->assertSame(1, BlockPlacement::query()
            ->where('container_type', $pageHost->containerType)
            ->where('container_id', $pageHost->containerId)
            ->where('slot', $pageHost->slot)
            ->where('block_id', $block->id)
            ->count());
    }

    #[Test]
    public function it_is_a_no_op_for_a_non_orphan_block(): void
    {
        $host = $this->libraryHost();
        $block = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'x']);

        $result = $this->svc->adoptOrphanIntoLibrary($block->id);

        $this->assertNull($result);
    }

    #[Test]
    public function it_is_a_no_op_for_a_missing_block(): void
    {
        $this->assertNull($this->svc->adoptOrphanIntoLibrary('does-not-exist'));
    }
}
