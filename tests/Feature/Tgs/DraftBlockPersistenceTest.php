<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class DraftBlockPersistenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_draft_block_has_no_placement_and_no_committed_at(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $draft = app(CanvasPersistence::class)->createDraftBlock($host, 'content');

        $this->assertNull($draft->committed_at);
        $this->assertSame(0, BlockPlacement::query()->where('block_id', $draft->id)->count());
    }

    #[Test]
    public function committing_a_draft_places_it_and_stamps_committed_at(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);
        $draft = $p->createDraftBlock($host, 'content');

        $p->commitDraft($host, $draft->id, $host->containerType, $host->containerId, $host->slot, null, ['content' => 'Готово']);

        $draft->refresh();
        $this->assertNotNull($draft->committed_at);
        $this->assertSame('Готово', $draft->data['content']);
        $this->assertSame(1, BlockPlacement::query()->where('block_id', $draft->id)->count());
    }

    #[Test]
    public function add_block_commits_immediately(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $block = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'column', [],
        );

        $this->assertNotNull($block->committed_at);
    }

    #[Test]
    public function committing_a_draft_prunes_media_not_matching_the_committed_uuid(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);
        $draft = $p->createDraftBlock($host, 'picture');

        $draft->addMedia(UploadedFile::fake()->image('keep.jpg'))
            ->withCustomProperties(['uuid' => 'keep-me'])
            ->toMediaCollection('content_builder');
        $draft->addMedia(UploadedFile::fake()->image('stale.jpg'))
            ->withCustomProperties(['uuid' => 'stale-uuid'])
            ->toMediaCollection('content_builder');

        $p->commitDraft($host, $draft->id, $host->containerType, $host->containerId, $host->slot, null, ['uuid' => 'keep-me']);

        $draft->refresh();
        $uuids = $draft->getMedia('content_builder')->pluck('custom_properties.uuid')->all();

        $this->assertSame(['keep-me'], $uuids);
    }
}
