<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use ExileOfAranei\ListOrdering\Support\GroupKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasLibraryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Task 2 (tgs-canvas as entities): the library canvas now roots on the BLOCK_LIBRARY
     * named-kind Canvas (TgsNamedCanvas::BlockLibrary), not the old `settings`/BlockLibrarySettings host.
     */
    private function libraryHost(): HostDescriptor
    {
        return Canvas::named(TgsNamedCanvas::BlockLibrary)->hostDescriptor();
    }

    private function libraryBlock(): Block
    {
        $host = $this->libraryHost();

        $lib = Block::updateOrCreate(['id' => 'LIB-X'], [
            'type' => 'content', 'data' => ['content' => 'reusable'], 'owner_container_type' => $host->containerType,
            'owner_container_id' => $host->containerId, 'committed_at' => now(),
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId, 'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'LIB-X']);

        return $lib;
    }

    #[Test]
    public function library_blocks_lists_the_library_root(): void
    {
        $this->libraryBlock();
        $demo = TgsDemo::factory()->create();

        $instance = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->instance();
        // @phpstan-ignore method.notFound (Livewire computed getter, resolved via magic __call on the base Component)
        $ids = array_column($instance->getLibraryBlocksProperty(), 'id');

        $this->assertContains('LIB-X', $ids);
    }

    #[Test]
    public function inserting_from_library_as_reference_shares_the_block(): void
    {
        $this->libraryBlock();
        $demo = TgsDemo::factory()->create();

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->call('insertFromLibrary', 'LIB-X', false);

        // Ссылка: тот же block_id размещён у страницы, оригинал владеет library-хостом, не склонирован.
        $this->assertTrue(BlockPlacement::query()->where('container_type', $demo->canvasDescriptor('content')->containerType)->where('slot', 'content')->where('container_id', (string) $demo->id)->where('block_id', 'LIB-X')->exists());
        $this->assertSame($this->libraryHost()->containerType, Block::find('LIB-X')->owner_container_type);
    }

    #[Test]
    public function inserting_from_library_as_copy_clones_the_block(): void
    {
        $this->libraryBlock();
        $demo = TgsDemo::factory()->create();

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->call('insertFromLibrary', 'LIB-X', true);

        $placed = BlockPlacement::query()->where('container_type', $demo->canvasDescriptor('content')->containerType)->where('slot', 'content')->where('container_id', (string) $demo->id)->value('block_id');
        $this->assertNotSame('LIB-X', $placed); // копия
        $this->assertNotSame($this->libraryHost()->containerType, Block::find($placed)->owner_container_type); // владеет страница, не библиотека
    }

    #[Test]
    public function adding_a_block_to_the_library_deep_copies_it_into_the_settings_host(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'promote me']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('addToLibraryAction', ['block' => $block->id])
            ->callMountedAction();

        $host = $this->libraryHost();
        $libCopy = BlockPlacement::query()
            ->where('container_type', $host->containerType)->where('container_id', $host->containerId)
            ->value('block_id');
        $this->assertNotNull($libCopy);
        $this->assertNotSame($block->id, $libCopy); // копия, не ссылка
        $this->assertSame($host->containerType, Block::find($libCopy)->owner_container_type);
    }

    #[Test]
    public function add_to_library_action_with_a_name_names_the_clone(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'promote me']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('addToLibraryAction', ['block' => $block->id])
            ->setActionData(['name' => 'Мой блок'])
            ->callMountedAction();

        $libHost = $this->libraryHost();
        $libCopyId = BlockPlacement::query()
            ->where('container_type', $libHost->containerType)->where('container_id', $libHost->containerId)
            ->value('block_id');

        $this->assertSame('Мой блок', Block::find($libCopyId)->name);
    }

    #[Test]
    public function add_to_library_action_without_a_name_leaves_the_clone_unnamed(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'promote me']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('addToLibraryAction', ['block' => $block->id])
            ->callMountedAction();

        $libHost = $this->libraryHost();
        $libCopyId = BlockPlacement::query()
            ->where('container_type', $libHost->containerType)->where('container_id', $libHost->containerId)
            ->value('block_id');

        $this->assertNull(Block::find($libCopyId)->name);
    }

    #[Test]
    public function get_library_blocks_property_includes_the_name(): void
    {
        $lib = $this->libraryBlock();
        $lib->name = 'Именованный блок';
        $lib->save();

        $demo = TgsDemo::factory()->create();

        $instance = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->instance();
        // @phpstan-ignore method.notFound (Livewire computed getter, resolved via magic __call on the base Component)
        $blocks = $instance->getLibraryBlocksProperty();
        $found = collect($blocks)->firstWhere('id', 'LIB-X');

        $this->assertSame('Именованный блок', $found['name']);
    }

    /**
     * Finding #1 (final review): a slot with an explicit `allowedChildren` allowlist
     * (Accordion's `items` slot only allows `accordion_item`) must be enforced on the
     * "from library" tab exactly like the "new" tab (availableBlocksFor/BlockChildPolicy),
     * both for the listing and for the actual insert.
     */
    private function libraryBlockOfType(string $id, string $type): Block
    {
        $host = $this->libraryHost();

        $lib = Block::updateOrCreate(['id' => $id], [
            'type' => $type, 'data' => [], 'owner_container_type' => $host->containerType,
            'owner_container_id' => $host->containerId, 'committed_at' => now(),
        ]);
        $placement = new BlockPlacement(['block_id' => $id]);
        $placement->placeAtEnd(GroupKey::of([
            'container_type' => $host->containerType, 'container_id' => $host->containerId, 'slot' => $host->slot,
        ]));

        return $lib;
    }

    #[Test]
    public function library_blocks_property_excludes_types_not_allowed_in_the_current_picker_slot(): void
    {
        $this->libraryBlockOfType('LIB-ITEM', 'accordion_item');
        $this->libraryBlockOfType('LIB-CONTENT', 'content');

        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $accordion = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'accordion', []);

        $instance = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker', $accordion->id, 'items')
            ->instance();
        // @phpstan-ignore method.notFound (Livewire computed getter, resolved via magic __call on the base Component)
        $ids = array_column($instance->getLibraryBlocksProperty(), 'id');

        $this->assertContains('LIB-ITEM', $ids);
        $this->assertNotContains('LIB-CONTENT', $ids);
    }

    #[Test]
    public function insert_from_library_refuses_a_type_not_allowed_in_the_current_picker_slot(): void
    {
        $this->libraryBlockOfType('LIB-CONTENT', 'content');

        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $accordion = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'accordion', []);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker', $accordion->id, 'items')
            ->call('insertFromLibrary', 'LIB-CONTENT', true);

        $this->assertFalse(
            BlockPlacement::query()->where('container_type', 'block')->where('container_id', $accordion->id)->where('slot', 'items')->exists()
        );
    }

    /**
     * Finding #2 (final review): addToLibrary() must reject a block that doesn't belong to
     * the mounted host (or is locked) — same server-side ownership guard as
     * duplicateRootBlock()/deleteChildBlock()/reorderChild(), not only a blade-level hide.
     */
    #[Test]
    public function add_to_library_refuses_a_block_foreign_to_the_mounted_host(): void
    {
        $owner = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $foreignBlock = app(CanvasPersistence::class)->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'content', ['content' => 'not yours']);

        $demo = TgsDemo::factory()->create();

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('addToLibraryAction', ['block' => $foreignBlock->id])
            ->callMountedAction();

        $host = $this->libraryHost();
        $libCopy = BlockPlacement::query()
            ->where('container_type', $host->containerType)->where('container_id', $host->containerId)
            ->exists();
        $this->assertFalse($libCopy);
    }

    /**
     * Finding (second review round): insertFromLibrary() must re-check that
     * pickerParentId belongs to the mounted host before writing a BlockPlacement into it —
     * pickerParentId is a public Livewire property, regidratable directly from the client,
     * bypassing the ownership gate that openPicker() applies when it sets the property.
     * Mirrors addChildBlock()/duplicateChildBlock()'s guard.
     */
    #[Test]
    public function insert_from_library_refuses_a_foreign_picker_parent_id(): void
    {
        $this->libraryBlock();

        $owner = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $foreignBlock = app(CanvasPersistence::class)->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'content', ['content' => 'not yours']);

        $demo = TgsDemo::factory()->create();

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->set('pickerParentId', $foreignBlock->id)
            ->call('insertFromLibrary', 'LIB-X', false);

        $this->assertFalse(
            BlockPlacement::query()->where('container_id', $foreignBlock->id)->where('block_id', 'LIB-X')->exists()
        );
    }

    /**
     * Task 2 (kebab-fix plan): the "add to library" button moved off the toolbar into a
     * kebab dropdown ("⋮", data-testid="node-menu"). Visibility of the kebab itself is
     * driven by the same @unless($isForeign) that used to gate the button directly, so an
     * owned block must still render the kebab trigger.
     */
    #[Test]
    public function node_menu_kebab_is_rendered_for_an_owned_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'mine']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->assertSeeHtml('data-testid="node-menu"')
            ->assertSeeHtml('data-testid="add-to-library"');
    }

    /**
     * Mirror of the above: a foreign (referenced) block has nothing to put in the kebab menu
     * (its only entry, "add to library", isn't meaningful on a reference), so the kebab
     * trigger itself must not render at all.
     */
    /**
     * Task 6, Step 4: naming a block via the edit modal's `_name` field is only meaningful
     * while the canvas is mounted ON the library host itself — this proves the happy path.
     */
    #[Test]
    public function editing_a_block_on_the_library_host_updates_its_name(): void
    {
        $lib = $this->libraryBlock();
        // libraryBlock()'s updateOrCreate() silently drops committed_at (not fillable) —
        // force it here so editBlockAction() takes the "editBlockData" branch, not commitDraft().
        $lib->forceFill(['committed_at' => now()])->save();
        $libraryGroup = Canvas::named(TgsNamedCanvas::BlockLibrary);

        Livewire::test(TgsCanvas::class, ['canvasId' => $libraryGroup->id])
            ->callAction(
                'editBlockAction',
                data: ['content' => 'reusable', '_name' => 'Переименован'],
                arguments: ['block' => $lib->id, 'type' => 'content', 'isRoot' => true],
            )
            ->assertHasNoErrors()
            ->assertSuccessful();

        $updated = Block::find($lib->id);
        $this->assertSame('Переименован', $updated->name);
        // The `_name` form field must never leak into the `data` JSON blob — it's a real
        // column, entirely separate from `data` (see the code review that caught this).
        $this->assertArrayNotHasKey('_name', $updated->data);
    }

    /**
     * Task 6, Step 4 (Решение A): on a normal (non-library) page's canvas, the `_name` field
     * must not appear in the edit modal's schema at all.
     */
    #[Test]
    public function editing_a_block_on_a_normal_page_does_not_expose_the_name_field(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $block = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'not a library block']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('editBlockAction', ['block' => $block->id, 'type' => 'content', 'isRoot' => true])
            ->assertDontSee(__('tgs::filament.tgs.canvas.library.name_field.label'));
    }

    /**
     * 2026-07-23 UX follow-up: naming only makes sense for a library block's own ROOT
     * placement (getLibraryBlocksProperty()'s root-only definition of a "library block") — a
     * block nested inside a composite library block isn't independently referenceable via
     * "Из библиотеки", so the name field must not appear when editing it (isRoot: false),
     * even though the canvas itself is the library host.
     */
    #[Test]
    public function editing_a_nested_block_on_the_library_host_does_not_expose_the_name_field(): void
    {
        $host = $this->libraryHost();

        $parent = Block::updateOrCreate(['id' => 'LIB-PARENT'], [
            'type' => 'column', 'data' => [], 'owner_container_type' => $host->containerType,
            'owner_container_id' => $host->containerId, 'committed_at' => now(),
        ]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId, 'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'LIB-PARENT']);

        $child = Block::updateOrCreate(['id' => 'LIB-CHILD'], [
            'type' => 'content', 'data' => ['content' => 'nested'], 'owner_container_type' => $host->containerType,
            'owner_container_id' => $host->containerId, 'committed_at' => now(),
        ]);
        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => 'LIB-PARENT', 'slot' => 'content', 'rank' => '000000', 'block_id' => 'LIB-CHILD']);

        $libraryGroup = Canvas::named(TgsNamedCanvas::BlockLibrary);

        Livewire::test(TgsCanvas::class, ['canvasId' => $libraryGroup->id])
            ->mountAction('editBlockAction', ['block' => 'LIB-CHILD', 'type' => 'content', 'isRoot' => false])
            ->assertDontSee(__('tgs::filament.tgs.canvas.library.name_field.label'));
    }

    /**
     * Node-toolbar declutter (2026-07-23 UX follow-up): "add to library" is still absent for a
     * foreign block (never meaningful on a reference), but the kebab moved from a single-entry
     * menu to housing every secondary action — including Detach, which IS meaningful here (it's
     * the only way to turn a reference into an owned clone) — so the trigger itself must now
     * render for a foreign root reference, unlike before this change.
     */
    #[Test]
    public function node_menu_kebab_renders_for_a_foreign_block_but_without_add_to_library(): void
    {
        $host = $this->libraryHost();
        $foreign = Block::updateOrCreate(['id' => 'LIB-KEBAB-FOREIGN'], [
            'type' => 'content', 'data' => ['content' => 'shared'],
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId,
        ]);
        $foreign->forceFill(['committed_at' => now()])->save();

        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        app(CanvasPersistence::class)->insertReference($host, $host->containerType, $host->containerId, $host->slot, $foreign->id);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->assertSeeHtml('data-testid="node-menu"')
            ->assertSeeHtml('data-testid="detach-block"')
            ->assertDontSeeHtml('data-testid="add-to-library"');
    }

    /**
     * Customer feedback (2026-07-21): "add to library" makes no sense on the library canvas
     * itself — the block is already in the library. Unlike the foreign-block case, this one
     * is an OWNED, unlocked block; the kebab must still disappear because its only entry
     * (`isLibraryHost()`) is never meaningful here.
     */
    /**
     * Node-toolbar declutter (2026-07-23 UX follow-up): "add to library" stays absent on the
     * library host itself, but the kebab now also houses History/Duplicate/etc. — all still
     * meaningful here — so the trigger itself must render, unlike before this change.
     */
    #[Test]
    public function node_menu_kebab_renders_on_the_library_host_but_without_add_to_library(): void
    {
        $lib = $this->libraryBlock();
        $lib->forceFill(['committed_at' => now()])->save();
        $libraryGroup = Canvas::named(TgsNamedCanvas::BlockLibrary);

        Livewire::test(TgsCanvas::class, ['canvasId' => $libraryGroup->id])
            ->assertSeeHtml('data-testid="node-menu"')
            ->assertSeeHtml('data-testid="history-block"')
            ->assertDontSeeHtml('data-testid="add-to-library"');
    }
}
