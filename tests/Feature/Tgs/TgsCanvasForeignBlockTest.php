<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Task 3 (срез 2a-1 "механика ссылок"): TgsCanvas gates UI for foreign (referenced) blocks —
 * read-only content, but unlink (delete = existing unlink semantics) and detach (turn the
 * reference into an owned clone) stay available. Builds on Task 1 (insertReference) and
 * Task 2 (detachBlock/subtreeHasReferences).
 */
class TgsCanvasForeignBlockTest extends TestCase
{
    use RefreshDatabase;

    private function referencedForeign(HostDescriptor $host): Block
    {
        $foreign = Block::updateOrCreate(['id' => 'FOR-CANVAS-1'], [
            'type' => 'content', 'data' => ['content' => 'shared'],
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
        ]);
        // `committed_at` isn't mass-assignable (Block::$fillable) — updateOrCreate() silently
        // drops it, leaving the fixture as an uncommitted draft. forceFill() bypasses the guard.
        $foreign->forceFill(['committed_at' => now()])->save();
        app(CanvasPersistence::class)->insertReference(
            $host, $host->containerType, $host->containerId, $host->slot, $foreign->id,
        );

        return $foreign;
    }

    #[Test]
    public function is_foreign_block_is_true_for_a_reference_and_false_for_an_owned_or_orphan_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $foreign = $this->referencedForeign($host);

        $owned = app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', [],
        );

        $orphan = Block::updateOrCreate(['id' => 'ORPHAN-CANVAS-1'], [
            'type' => 'content', 'data' => [], 'owner_container_type' => 'orphan',
            'owner_container_id' => 'unknown', 'committed_at' => now(),
        ]);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id]);

        // Livewire\Testable::instance() is typed to the generic Livewire\Component base class —
        // Larastan can't narrow it to TgsCanvas, hence the explicit @var below.
        /** @var TgsCanvas $instance */
        $instance = $component->instance();

        $this->assertTrue($instance->isForeignBlock($foreign->id));
        $this->assertFalse($instance->isForeignBlock($owned->id));
        $this->assertFalse($instance->isForeignBlock($orphan->id));
        $this->assertFalse($instance->isForeignBlock('NON-EXISTENT-ID'));
    }

    #[Test]
    public function detach_block_action_replaces_the_reference_with_an_owned_clone(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $foreign = $this->referencedForeign($host);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->callAction('detachBlockAction', arguments: [
                'block' => $foreign->id,
                'containerType' => $host->containerType,
                'containerId' => $host->containerId,
                'slot' => $host->slot,
            ])
            ->assertSuccessful();

        $placed = BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->value('block_id');

        $this->assertNotSame($foreign->id, $placed);
        $clone = Block::find($placed);
        $this->assertNotNull($clone);
        $this->assertSame($host->containerType, $clone->owner_container_type);
        $this->assertSame($host->containerId, $clone->owner_container_id);

        // Original stays intact — this was a detach, not a delete.
        $this->assertNotNull(Block::find($foreign->id));
    }

    #[Test]
    public function editing_a_foreign_block_through_the_edit_action_is_a_no_op(): void
    {
        // Existing ownership gate in CanvasPersistence::editBlockData — confirmed here as
        // TgsCanvas-level behavior, not re-implemented.
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $foreign = $this->referencedForeign($host);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->callAction(
                'editBlockAction',
                data: ['content' => 'Попытка правки'],
                arguments: ['block' => $foreign->id, 'type' => 'content'],
            )
            ->assertSuccessful();

        $this->assertSame('shared', Block::find($foreign->id)->data['content']);
    }

    #[Test]
    public function deleting_a_foreign_root_block_unlinks_it_without_destroying_the_original(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $foreign = $this->referencedForeign($host);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('deleteRootBlock', $foreign->id)
            ->assertSuccessful();

        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->where('block_id', $foreign->id)
            ->count());

        $this->assertNotNull(Block::find($foreign->id), 'the shared original must survive an unlink');
    }

    #[Test]
    public function deleting_a_foreign_child_block_unlinks_it_without_destroying_the_original(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        $parent = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);

        $foreign = Block::updateOrCreate(['id' => 'FOR-CANVAS-CHILD-1'], [
            'type' => 'content', 'data' => ['content' => 'shared child'],
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
        ]);
        $foreign->forceFill(['committed_at' => now()])->save();
        $p->insertReference($host, 'block', $parent->id, 'content', $foreign->id);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('deleteChildBlock', $parent->id, 'content', $foreign->id)
            ->assertSuccessful();

        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $parent->id)
            ->where('slot', 'content')
            ->where('block_id', $foreign->id)
            ->count());

        $this->assertNotNull(Block::find($foreign->id), 'the shared original must survive an unlink');
    }

    /**
     * Finding #1 (final review, срез 2a-1): a node deeper inside a foreign subtree resolves its
     * parent/slot arguments to the FOREIGN block, not the host — deleteChildBlock() must no-op
     * there, or it unlinks the placement inside the shared container itself (visible to every
     * host referencing it), not just this host's view of it.
     */
    #[Test]
    public function delete_child_block_is_a_no_op_when_the_parent_is_a_foreign_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        // A foreign root container (column) referenced by this host, with its own owned child
        // placed inside it — the child's containerId is the FOREIGN column's id, not the host.
        $foreignParent = Block::updateOrCreate(['id' => 'FOR-CANVAS-PARENT-1'], [
            'type' => 'column', 'data' => [],
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
        ]);
        $foreignParent->forceFill(['committed_at' => now()])->save();
        $p->insertReference($host, $host->containerType, $host->containerId, $host->slot, $foreignParent->id);

        $child = Block::updateOrCreate(['id' => 'FOR-CANVAS-PARENT-CHILD-1'], [
            'type' => 'content', 'data' => ['content' => 'nested inside foreign parent'],
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
        ]);
        $child->forceFill(['committed_at' => now()])->save();
        BlockPlacement::forceCreate([
            'container_type' => 'block', 'container_id' => $foreignParent->id, 'slot' => 'content',
            'block_id' => $child->id, 'rank' => '000000',
        ]);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('deleteChildBlock', $foreignParent->id, 'content', $child->id)
            ->assertSuccessful();

        $this->assertSame(1, BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $foreignParent->id)
            ->where('slot', 'content')
            ->where('block_id', $child->id)
            ->count(), 'the placement inside the foreign parent must survive — it belongs to a shared container');
    }

    /**
     * Finding #1 (final review, срез 2a-1): same leak via detachBlockAction — its
     * containerId/containerType arguments for a nested node resolve to the FOREIGN parent block,
     * so detaching there would replace the shared container's own reference edge for every
     * consuming host, not just this one.
     */
    #[Test]
    public function detach_block_action_is_a_no_op_when_the_container_is_a_foreign_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        $foreignParent = Block::updateOrCreate(['id' => 'FOR-CANVAS-PARENT-2'], [
            'type' => 'column', 'data' => [],
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
        ]);
        $foreignParent->forceFill(['committed_at' => now()])->save();
        $p->insertReference($host, $host->containerType, $host->containerId, $host->slot, $foreignParent->id);

        $nestedForeign = Block::updateOrCreate(['id' => 'FOR-CANVAS-NESTED-1'], [
            'type' => 'content', 'data' => ['content' => 'nested reference'],
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
        ]);
        $nestedForeign->forceFill(['committed_at' => now()])->save();
        BlockPlacement::forceCreate([
            'container_type' => 'block', 'container_id' => $foreignParent->id, 'slot' => 'content',
            'block_id' => $nestedForeign->id, 'rank' => '000000',
        ]);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->callAction('detachBlockAction', arguments: [
                'block' => $nestedForeign->id,
                'containerType' => 'block',
                'containerId' => $foreignParent->id,
                'slot' => 'content',
            ])
            ->assertSuccessful();

        $placed = BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $foreignParent->id)
            ->where('slot', 'content')
            ->value('block_id');

        $this->assertSame($nestedForeign->id, $placed, 'the shared container edge must be untouched by a detach requested through a foreign parent');
    }

    /**
     * Finding #1 (final review, срез 2a-1): blade-level gate — detach/unlink controls must not
     * even render for a node deeper inside a foreign subtree, matching the server-side no-op.
     */
    #[Test]
    public function detach_and_unlink_buttons_are_not_rendered_for_a_node_inside_a_foreign_subtree(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        $foreignParent = Block::updateOrCreate(['id' => 'FOR-CANVAS-PARENT-3'], [
            'type' => 'column', 'data' => [],
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
        ]);
        $foreignParent->forceFill(['committed_at' => now()])->save();
        $p->insertReference($host, $host->containerType, $host->containerId, $host->slot, $foreignParent->id);

        $nestedForeign = Block::updateOrCreate(['id' => 'FOR-CANVAS-NESTED-2'], [
            'type' => 'content', 'data' => ['content' => 'nested reference'],
            'owner_container_type' => 'settings', 'owner_container_id' => 'block_library.value',
        ]);
        $nestedForeign->forceFill(['committed_at' => now()])->save();
        BlockPlacement::forceCreate([
            'container_type' => 'block', 'container_id' => $foreignParent->id, 'slot' => 'content',
            'block_id' => $nestedForeign->id, 'rank' => '000000',
        ]);

        // Filament escapes action arguments through Illuminate\Support\Js::from(), which quotes
        // as " inside a JSON.parse('...') call rather than literal double quotes.
        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->assertDontSeeHtml("mountAction('detachBlockAction', JSON.parse('{\\u0022block\\u0022:\\u0022{$nestedForeign->id}\\u0022")
            ->assertDontSeeHtml("mountAction('deleteBlockAction', JSON.parse('{\\u0022block\\u0022:\\u0022{$nestedForeign->id}\\u0022");
    }
}
