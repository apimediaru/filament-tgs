<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class DraftBlockPruneTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_old_uncommitted_draft_is_pruned(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $draft = app(CanvasPersistence::class)->createDraftBlock($host, 'content');
        $draft->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $this->artisan('model:prune', ['--model' => [Block::class]])->assertSuccessful();

        $this->assertNull(Block::find($draft->id));
    }

    #[Test]
    public function a_fresh_uncommitted_draft_is_not_pruned(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $draft = app(CanvasPersistence::class)->createDraftBlock($host, 'content');

        $this->artisan('model:prune', ['--model' => [Block::class]])->assertSuccessful();

        $this->assertNotNull(Block::find($draft->id));
    }

    #[Test]
    public function a_committed_block_with_a_placement_is_not_pruned_even_if_committed_at_is_missing(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);
        $draft = $p->createDraftBlock($host, 'content');
        $p->commitDraft($host, $draft->id, $host->containerType, $host->containerId, $host->slot, null, ['content' => 'Готово']);

        // Defense-in-depth: even if committed_at somehow reverts to null (backfill/commit-path bug),
        // the presence of a placement must independently protect the block from pruning.
        $draft->forceFill(['committed_at' => null, 'created_at' => now()->subDays(2)])->saveQuietly();

        $this->artisan('model:prune', ['--model' => [Block::class]])->assertSuccessful();

        $this->assertNotNull(Block::find($draft->id));
    }
}
