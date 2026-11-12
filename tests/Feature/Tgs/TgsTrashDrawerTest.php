<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Livewire\TgsTrashDrawer;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockDeletion;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Этап 4, Слайс D: UI корзины. Слайс C (`CanvasPersistenceRestoreTest`) уже exhaustively
 * покрывает якорную логику `restoreBlock()` самого сервиса — эти тесты проверяют только сам
 * Livewire-слой поверх него: список/скоуп по канвасу, счётчик, кнопку «Вернуть», toast на
 * `positionMatched`, read-only-гейт и подсчёт вложенных.
 */
class TgsTrashDrawerTest extends TestCase
{
    use RefreshDatabase;

    private CanvasPersistence $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CanvasPersistence::class);
    }

    private function group(): Canvas
    {
        $demo = TgsDemo::factory()->create();

        return $demo->canvas('content');
    }

    private function host(Canvas $group): HostDescriptor
    {
        return $group->hostDescriptor();
    }

    #[Test]
    public function trash_lists_only_deleted_roots_of_the_current_canvas(): void
    {
        $group = $this->group();
        $host = $this->host($group);
        $otherGroup = $this->group();
        $otherHost = $this->host($otherGroup);

        $block = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['body' => 'mine']);
        $this->svc->deleteBlock($host, $host->containerType, $host->containerId, $host->slot, $block->id);

        $otherBlock = $this->svc->addBlock($otherHost, $otherHost->containerType, $otherHost->containerId, $otherHost->slot, 'content', ['body' => 'other']);
        $this->svc->deleteBlock($otherHost, $otherHost->containerType, $otherHost->containerId, $otherHost->slot, $otherBlock->id);

        $entries = Livewire::test(TgsTrashDrawer::class, ['canvasId' => $group->id])
            ->instance()
            ->getEntriesProperty(); // @phpstan-ignore method.notFound

        $this->assertCount(1, $entries);
        $this->assertSame($block->id, BlockDeletion::query()->find($entries->first()->id)->block_id);
    }

    #[Test]
    public function trash_button_is_always_visible_but_disabled_at_zero_and_enabled_with_count_otherwise(): void
    {
        $group = $this->group();
        $host = $this->host($group);

        // Always rendered — even with nothing in the trash yet — so a user has somewhere to
        // look for it; `disabled` (not hidden) communicates the empty state instead.
        $emptyTrash = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $emptyTrash->assertSeeHtml('data-testid="trash-button"')->assertSee('Корзина (0)');
        $this->assertMatchesRegularExpression('/\sdisabled(\s|>)/', $this->trashButtonHtml($emptyTrash));

        $block = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['body' => 'x']);
        $this->svc->deleteBlock($host, $host->containerType, $host->containerId, $host->slot, $block->id);

        $withOneEntry = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $withOneEntry->assertSeeHtml('data-testid="trash-button"')->assertSee('Корзина (1)');
        // Not a plain `str_contains('disabled', ...)` check — the button's own Tailwind classes
        // legitimately contain the literal substring "disabled:" (the `disabled:` variant
        // prefix) regardless of actual state; only a bare `disabled` HTML attribute counts.
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(\s|>)/', $this->trashButtonHtml($withOneEntry));
    }

    #[Test]
    public function trash_counter_drops_back_after_restoring_the_only_deleted_block(): void
    {
        // Regression: `block_deletions` is append-only — restoreBlock() never deletes the
        // journal row, only Slice E's GC eventually does — so the header counter must filter
        // by the block's CURRENT deleted_op_id (same check as the drawer's own list), not just
        // count rows for the canvas, or it would keep showing a stale "1" forever after restore.
        $group = $this->group();
        $host = $this->host($group);

        $block = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['body' => 'x']);
        $this->svc->deleteBlock($host, $host->containerType, $host->containerId, $host->slot, $block->id);
        $deletion = BlockDeletion::query()->where('block_id', $block->id)->sole();

        Livewire::test(TgsTrashDrawer::class, ['canvasId' => $group->id])->call('restore', $deletion->id);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->assertSee('Корзина (0)');
    }

    private function trashButtonHtml(Testable $component): string
    {
        $matches = [];
        preg_match('/<button[^>]*data-testid="trash-button"[^>]*>/', $component->html(), $matches);

        return $matches[0] ?? '';
    }

    #[Test]
    public function restoring_removes_the_entry_from_trash_and_brings_the_block_back_into_the_tree(): void
    {
        $group = $this->group();
        $host = $this->host($group);

        $block = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['body' => 'x']);
        $this->svc->deleteBlock($host, $host->containerType, $host->containerId, $host->slot, $block->id);
        $deletion = BlockDeletion::query()->where('block_id', $block->id)->sole();

        $drawer = Livewire::test(TgsTrashDrawer::class, ['canvasId' => $group->id])
            ->call('restore', $deletion->id)
            ->assertDispatched('tgs-canvas-changed', canvasId: $group->id);

        // The `block_deletions` row itself is append-only — restoreBlock() (Слайс C) never
        // deletes it, only Slice E's future GC does. The entry must instead disappear from the
        // drawer's own LIST (filtered by describeEntry() checking the block's current
        // deleted_op_id), which is what the UI-facing requirement actually cares about.
        $this->assertTrue(BlockDeletion::query()->where('id', $deletion->id)->exists());
        $this->assertTrue($drawer->instance()->getEntriesProperty()->isEmpty());

        $this->assertSame(
            [$block->id],
            BlockPlacement::query()
                ->where('container_type', $host->containerType)
                ->where('container_id', $host->containerId)
                ->where('slot', $host->slot)
                ->pluck('block_id')
                ->all(),
        );
    }

    #[Test]
    public function restoring_at_the_original_position_sends_a_plain_success_toast(): void
    {
        $group = $this->group();
        $host = $this->host($group);

        $block = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['body' => 'x']);
        $this->svc->deleteBlock($host, $host->containerType, $host->containerId, $host->slot, $block->id);
        $deletion = BlockDeletion::query()->where('block_id', $block->id)->sole();

        Livewire::test(TgsTrashDrawer::class, ['canvasId' => $group->id])
            ->call('restore', $deletion->id)
            ->assertNotified(__('tgs::filament.tgs.canvas.trash.restored'))
            ->assertNotNotified(__('tgs::filament.tgs.canvas.trash.restored_position_mismatch'));
    }

    #[Test]
    public function restoring_to_a_different_position_warns_via_toast(): void
    {
        $group = $this->group();
        $host = $this->host($group);

        $tabs = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'tabs', []);
        // Historical data predates insertion checks: a content leaf in tabs.items.
        // Construct the fixture directly; new production insertions must reject it.
        $leaf = Block::factory()->content()->create([
            'owner_container_type' => $host->containerType,
            'owner_container_id' => $host->containerId,
        ]);
        BlockPlacement::forceCreate([
            'container_type' => 'block', 'container_id' => $tabs->id,
            'slot' => 'items', 'block_id' => $leaf->id, 'rank' => '000000',
        ]);
        $this->svc->deleteBlock($host, 'block', $tabs->id, 'items', $leaf->id);
        $deletion = BlockDeletion::query()->where('block_id', $leaf->id)->sole();

        Livewire::test(TgsTrashDrawer::class, ['canvasId' => $group->id])
            ->call('restore', $deletion->id)
            ->assertNotified(__('tgs::filament.tgs.canvas.trash.restored_position_mismatch'));
    }

    #[Test]
    public function trash_does_not_render_and_cannot_be_opened_in_readonly_mode(): void
    {
        $group = $this->group();
        $host = $this->host($group);

        $block = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['body' => 'x']);
        $this->svc->deleteBlock($host, $host->containerType, $host->containerId, $host->slot, $block->id);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id, 'readonly' => true])
            ->assertDontSee('data-testid="trash-button"', false)
            ->assertDontSeeHtml('tgs-trash-drawer');

        Livewire::test(TgsTrashDrawer::class, ['canvasId' => $group->id, 'readonly' => true])
            ->call('openTrash', $group->id)
            ->assertDontSee(__('tgs::filament.tgs.canvas.trash.title'));
    }

    #[Test]
    public function nested_count_reflects_the_whole_deleted_subtree(): void
    {
        $group = $this->group();
        $host = $this->host($group);

        $parent = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['body' => 'parent']);
        $childA = $this->svc->addBlock($host, 'block', $parent->id, 'children', 'content', ['body' => 'a']);
        $childB = $this->svc->addBlock($host, 'block', $parent->id, 'children', 'content', ['body' => 'b']);
        $grandchild = $this->svc->addBlock($host, 'block', $childA->id, 'children', 'content', ['body' => 'c']);

        $this->svc->deleteBlock($host, $host->containerType, $host->containerId, $host->slot, $parent->id);
        $deletion = BlockDeletion::query()->where('block_id', $parent->id)->sole();

        $entries = Livewire::test(TgsTrashDrawer::class, ['canvasId' => $group->id])
            ->instance()
            ->getEntriesProperty(); // @phpstan-ignore method.notFound

        $entry = $entries->sole();
        $this->assertSame($deletion->id, $entry->id);
        $this->assertSame(3, $entry->nestedCount, 'childA + childB + grandchild, excluding the root itself');
        $this->assertNotEmpty([$childA->id, $childB->id, $grandchild->id]);
    }
}
