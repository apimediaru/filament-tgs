<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Regression tests (plan B2 fix, review finding): TgsCanvas::duplicateRootBlock/
 * duplicateChildBlock/addChildBlock/reorderChild trusted a client-supplied block id
 * without checking it belongs to the mounted host — a crafted Livewire call could
 * duplicate/attach-under/reorder blocks belonging to a completely different host.
 * These tests prove each mutation is a no-op when the referenced block belongs to
 * another host's tree.
 */
class TgsCanvasOwnershipTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function duplicating_a_block_from_a_different_host_is_a_no_op(): void
    {
        $hostA = TgsDemo::factory()->create();
        $groupA = $hostA->canvas('content');
        $descriptorA = $groupA->hostDescriptor();

        $hostB = TgsDemo::factory()->create();
        $groupB = $hostB->canvas('content');
        $descriptorB = $groupB->hostDescriptor();

        Block::factory()->content(['content' => 'Чужой блок'])->withId('FOREIGN-ROOT')->create([
            'owner_container_type' => $descriptorB->containerType,
            'owner_container_id' => (string) $hostB->id,
        ]);
        BlockPlacement::forceCreate([
            'container_type' => $descriptorB->containerType,
            'container_id' => $descriptorB->containerId,
            'slot' => $descriptorB->slot,
            'rank' => '000000',
            'block_id' => 'FOREIGN-ROOT',
        ]);

        Livewire::test(TgsCanvas::class, ['canvasId' => $groupA->id])
            ->call('duplicateRootBlock', 'FOREIGN-ROOT')
            ->assertSuccessful();

        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', $descriptorA->containerType)
            ->where('container_id', $descriptorA->containerId)
            ->where('slot', $descriptorA->slot)
            ->count());

        // и родительская сторона: чужой parentBlockId для duplicateChildBlock тоже no-op
        Livewire::test(TgsCanvas::class, ['canvasId' => $groupA->id])
            ->call('duplicateChildBlock', 'FOREIGN-ROOT', 'content', 'FOREIGN-ROOT')
            ->assertSuccessful();

        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', 'FOREIGN-ROOT')
            ->where('slot', 'content')
            ->count());
    }

    #[Test]
    public function duplicating_an_own_block_under_a_foreign_parent_is_a_no_op(): void
    {
        // Closing fix (plan B2 review): duplicateChildBlock's SOURCE block belongs to the caller's
        // own host, but the PARENT it would be inserted under belongs to a different host — this
        // must be rejected too, otherwise an attacker could smuggle an own block into a foreign tree.
        $hostA = TgsDemo::factory()->create();
        $groupA = $hostA->canvas('content');
        $descriptorA = $groupA->hostDescriptor();

        $ownBlock = Block::factory()->content(['content' => 'Свой блок'])->withId('OWN-SOURCE')->create([
            'owner_container_type' => $descriptorA->containerType,
            'owner_container_id' => (string) $hostA->id,
        ]);
        BlockPlacement::forceCreate([
            'container_type' => $descriptorA->containerType,
            'container_id' => $descriptorA->containerId,
            'slot' => $descriptorA->slot,
            'rank' => '000000',
            'block_id' => $ownBlock->id,
        ]);

        $hostB = TgsDemo::factory()->create();
        $groupB = $hostB->canvas('content');
        $descriptorB = $groupB->hostDescriptor();

        $foreignParent = Block::factory()->column()->withId('FOREIGN-PARENT-3')->create([
            'owner_container_type' => $descriptorB->containerType,
            'owner_container_id' => (string) $hostB->id,
        ]);
        BlockPlacement::forceCreate([
            'container_type' => $descriptorB->containerType,
            'container_id' => $descriptorB->containerId,
            'slot' => $descriptorB->slot,
            'rank' => '000000',
            'block_id' => $foreignParent->id,
        ]);

        Livewire::test(TgsCanvas::class, ['canvasId' => $groupA->id])
            ->call('duplicateChildBlock', $foreignParent->id, 'content', $ownBlock->id)
            ->assertSuccessful();

        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $foreignParent->id)
            ->where('slot', 'content')
            ->count(), 'no new placement must be created under the foreign parent');

        $this->assertSame(1, Block::query()->where('type', 'content')->count(),
            'no duplicate block must be created at all — the own source, own block count stays at 1');
    }

    #[Test]
    public function adding_a_child_block_under_a_foreign_parent_is_a_no_op(): void
    {
        $hostA = TgsDemo::factory()->create();
        $groupA = $hostA->canvas('content');

        $hostB = TgsDemo::factory()->create();
        $groupB = $hostB->canvas('content');
        $descriptorB = $groupB->hostDescriptor();

        $foreignParent = Block::factory()->column()->withId('FOREIGN-PARENT')->create([
            'owner_container_type' => $descriptorB->containerType,
            'owner_container_id' => (string) $hostB->id,
        ]);
        BlockPlacement::forceCreate([
            'container_type' => $descriptorB->containerType,
            'container_id' => $descriptorB->containerId,
            'slot' => $descriptorB->slot,
            'rank' => '000000',
            'block_id' => $foreignParent->id,
        ]);

        Livewire::test(TgsCanvas::class, ['canvasId' => $groupA->id])
            ->call('addChildBlock', $foreignParent->id, 'content', 'content')
            ->assertSuccessful();

        $this->assertSame(0, BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $foreignParent->id)
            ->where('slot', 'content')
            ->count());
    }

    #[Test]
    public function reordering_children_under_a_foreign_parent_is_a_no_op(): void
    {
        $hostA = TgsDemo::factory()->create();
        $groupA = $hostA->canvas('content');

        $hostB = TgsDemo::factory()->create();
        $groupB = $hostB->canvas('content');
        $descriptorB = $groupB->hostDescriptor();
        $ownB = ['owner_container_type' => $descriptorB->containerType, 'owner_container_id' => (string) $hostB->id];

        $foreignParent = Block::factory()->column()->withId('FOREIGN-PARENT-2')->create($ownB);
        BlockPlacement::forceCreate([
            'container_type' => $descriptorB->containerType,
            'container_id' => $descriptorB->containerId,
            'slot' => $descriptorB->slot,
            'rank' => '000000',
            'block_id' => $foreignParent->id,
        ]);

        Block::factory()->content()->withId('FOREIGN-CHILD-1')->create($ownB);
        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => $foreignParent->id,
            'slot' => 'content', 'rank' => '000000', 'block_id' => 'FOREIGN-CHILD-1']);

        Block::factory()->content()->withId('FOREIGN-CHILD-2')->create($ownB);
        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => $foreignParent->id,
            'slot' => 'content', 'rank' => '000001', 'block_id' => 'FOREIGN-CHILD-2']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $groupA->id])
            ->call('reorderChild', $foreignParent->id, 'content', ['FOREIGN-CHILD-2', 'FOREIGN-CHILD-1'])
            ->assertSuccessful();

        $order = BlockPlacement::query()
            ->where('container_type', 'block')
            ->where('container_id', $foreignParent->id)
            ->where('slot', 'content')
            ->orderBy('rank')
            ->pluck('block_id')
            ->all();

        $this->assertSame(['FOREIGN-CHILD-1', 'FOREIGN-CHILD-2'], $order, 'foreign host placements must be untouched');
    }
}
