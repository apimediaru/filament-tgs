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

class ProvisionalPruneCleansContentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pruning_an_abandoned_provisional_record_removes_its_blocks_and_placements(): void
    {
        $demo = TgsDemo::provisional();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', [],
        );

        // Состарить запись за порог прунинга (сутки).
        $demo->forceFill(['provisional_at' => now()->subDays(2)])->saveQuietly();

        $this->artisan('model:prune', ['--model' => [TgsDemo::class]])->assertSuccessful();

        $this->assertNull(TgsDemo::withoutGlobalScopes()->find($demo->id));
        $this->assertNull(Block::find($block->id));
        $this->assertSame(0, BlockPlacement::query()->count());
    }
}
