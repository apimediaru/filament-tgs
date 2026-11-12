<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Filament\Pages\ManageOrphanedBlocks;
use ApiMedia\Tgs\Livewire\OrphanedBlocksTable;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Отдельная страница «Осиротевшие блоки»: список только осиротевших блоков (owner_container_type
 * = 'orphan'), плюс три действия поверх него — Редактировать / В библиотеку / Удалить везде.
 */
class OrphanedBlocksTableTest extends TestCase
{
    use RefreshDatabase;

    private CanvasPersistence $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CanvasPersistence::class);
    }

    #[Test]
    public function the_list_shows_only_orphaned_blocks_not_regular_ones(): void
    {
        $orphan = Block::factory()->orphan()->create();

        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $owned = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'owned']);

        $entries = Livewire::test(OrphanedBlocksTable::class)
            ->instance()
            ->getEntriesProperty(); // @phpstan-ignore method.notFound

        $ids = $entries->pluck('id')->all();
        $this->assertContains($orphan->id, $ids);
        $this->assertNotContains($owned->id, $ids);
    }

    #[Test]
    public function editing_an_orphaned_block_persists_through_edit_block_data(): void
    {
        $block = Block::factory()->orphan()->create(['type' => 'content', 'data' => ['content' => 'old']]);

        Livewire::test(OrphanedBlocksTable::class)
            ->callAction(
                'editBlockAction',
                data: ['content' => 'new'],
                arguments: ['block' => $block->id, 'type' => 'content'],
            )
            ->assertSuccessful();

        $this->assertStringContainsString('new', Block::find($block->id)->data['content']);
    }

    #[Test]
    public function deleting_an_orphaned_block_force_deletes_it_everywhere_and_soft_deletes_it(): void
    {
        $block = Block::factory()->orphan()->create();
        $demo = TgsDemo::factory()->create();
        $pageHost = HostDescriptor::forModelSlot($demo, 'content');
        $this->svc->insertReference($pageHost, $pageHost->containerType, $pageHost->containerId, $pageHost->slot, $block->id);

        Livewire::test(OrphanedBlocksTable::class)
            ->callAction('deleteBlockAction', arguments: ['block' => $block->id])
            ->assertSuccessful();

        $block->refresh();
        $this->assertNotNull($block->deleted_at, 'forceDeleteEverywhere() soft-deletes via cascadeOwnedSubtree(), it does not hard-delete the row');
        $this->assertSame(0, BlockPlacement::query()->where('block_id', $block->id)->count());
    }

    #[Test]
    public function adopting_an_orphaned_block_into_the_library_removes_it_from_the_orphan_list(): void
    {
        $block = Block::factory()->orphan()->create();

        Livewire::test(OrphanedBlocksTable::class)
            ->callAction('adoptIntoLibraryAction', arguments: ['block' => $block->id])
            ->assertSuccessful();

        $block->refresh();
        $this->assertNotSame('orphan', $block->owner_container_type);

        $libraryHost = Canvas::named(TgsNamedCanvas::BlockLibrary)->hostDescriptor();
        $this->assertSame(1, BlockPlacement::query()
            ->where('container_type', $libraryHost->containerType)
            ->where('container_id', $libraryHost->containerId)
            ->where('slot', $libraryHost->slot)
            ->where('block_id', $block->id)
            ->count());

        $entries = Livewire::test(OrphanedBlocksTable::class)
            ->instance()
            ->getEntriesProperty(); // @phpstan-ignore method.notFound

        $this->assertNotContains($block->id, $entries->pluck('id')->all());
    }

    #[Test]
    public function a_locked_block_cannot_be_edited_or_deleted(): void
    {
        $block = Block::factory()->orphan()->locked()->create(['type' => 'content', 'data' => ['content' => 'old']]);

        Livewire::test(OrphanedBlocksTable::class)
            ->callAction(
                'editBlockAction',
                data: ['content' => 'new'],
                arguments: ['block' => $block->id, 'type' => 'content'],
            );

        $this->assertSame('old', Block::find($block->id)->data['content']);

        Livewire::test(OrphanedBlocksTable::class)
            ->callAction('deleteBlockAction', arguments: ['block' => $block->id]);

        $this->assertNull(Block::find($block->id)->deleted_at);
    }

    #[Test]
    public function trashed_and_draft_orphans_are_neither_listed_nor_counted_in_the_badge(): void
    {
        // Hotfix slice 1, bug 8: a block deleted from this very screen goes to the trash but keeps
        // owner=orphan — it must disappear from the list and the badge, as must an uncommitted draft.
        $alive = Block::factory()->orphan()->create();
        $trashed = Block::factory()->orphan()->create(['deleted_at' => now(), 'deleted_op_id' => (string) Str::ulid()]);
        $draft = Block::factory()->orphan()->draft()->create();

        $ids = Livewire::test(OrphanedBlocksTable::class)
            ->instance()
            ->getEntriesProperty() // @phpstan-ignore method.notFound
            ->pluck('id')
            ->all();

        $this->assertContains($alive->id, $ids);
        $this->assertNotContains($trashed->id, $ids);
        $this->assertNotContains($draft->id, $ids);

        Cache::forget('filament:page:orphaned-blocks:count');
        $this->assertSame('1', ManageOrphanedBlocks::getNavigationBadge());
    }
}
