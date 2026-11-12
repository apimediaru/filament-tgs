<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Filament\Forms\Components\Radio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Workbench\App\Models\TgsDemo;

class TgsCanvasTokenActionTest extends TestCase
{
    use RefreshDatabase;

    private function orphanBlock(string $id, string $type = 'content'): Block
    {
        return Block::create([
            'id' => $id, 'type' => $type, 'data' => ['content' => 'via action'],
            'owner_container_type' => 'orphan', 'owner_container_id' => 'unknown',
        ]);
    }

    #[Test]
    public function submitting_a_valid_token_inserts_the_block(): void
    {
        $this->orphanBlock('ACTION-TOKEN-OK');
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->mountAction('insertFromTokenAction')
            ->setActionData(['token' => 'ACTION-TOKEN-OK', 'asCopy' => '0'])
            ->callMountedAction()
            ->assertHasNoErrors();

        $this->assertTrue(BlockPlacement::query()
            ->where('container_type', $host->containerType)
            ->where('container_id', $host->containerId)
            ->where('slot', $host->slot)
            ->where('block_id', 'ACTION-TOKEN-OK')
            ->exists());

        // insertFromToken() (called from this action's own ->action() closure) unmounts once
        // for itself; the action's own submit machinery pops the second (parent
        // insertBlockAction) level — both should be gone.
        $this->assertSame([], Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->get('mountedActions'));
    }

    #[Test]
    public function submitting_an_unknown_token_shows_an_inline_error(): void
    {
        $demo = TgsDemo::factory()->create();

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker')
            ->mountAction('insertFromTokenAction')
            ->setActionData(['token' => 'DOES-NOT-EXIST', 'asCopy' => '1'])
            ->callMountedAction()
            ->assertHasActionErrors(['token']);
    }

    #[Test]
    public function submitting_a_disallowed_type_shows_an_inline_error(): void
    {
        $this->orphanBlock('ACTION-TOKEN-BAD-TYPE', 'double_column');
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $parent = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'accordion', []);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker', $parent->id, 'items')
            ->mountAction('insertFromTokenAction')
            ->setActionData(['token' => 'ACTION-TOKEN-BAD-TYPE', 'asCopy' => '1'])
            ->callMountedAction()
            ->assertHasActionErrors(['token']);
    }

    #[Test]
    public function submitting_a_cyclic_reference_shows_an_inline_error(): void
    {
        $this->orphanBlock('ACTION-CYCLE-ROOT', 'column');
        $child = $this->orphanBlock('ACTION-CYCLE-CHILD');
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        app(CanvasPersistence::class)->copyBlockInto($host, $host->containerType, $host->containerId, $host->slot, 'ACTION-CYCLE-ROOT');
        $rootCloneId = BlockPlacement::query()->where('container_type', $host->containerType)->where('container_id', $host->containerId)->first()->block_id;
        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => $rootCloneId, 'slot' => 'content', 'rank' => '000000', 'block_id' => $child->id]);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker', $rootCloneId, 'content')
            ->mountAction('insertFromTokenAction')
            ->setActionData(['token' => $rootCloneId, 'asCopy' => '0'])
            ->callMountedAction()
            ->assertHasActionErrors(['token']);
    }

    #[Test]
    public function submitting_as_copy_never_triggers_the_cycle_check(): void
    {
        // A copy always gets a brand-new id, so pasting a block's own ancestor-chain id back
        // in AS A COPY is always safe — the cycle rule only runs when !asCopy.
        $this->orphanBlock('ACTION-CYCLE-AS-COPY-ROOT', 'column');
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        app(CanvasPersistence::class)->copyBlockInto($host, $host->containerType, $host->containerId, $host->slot, 'ACTION-CYCLE-AS-COPY-ROOT');
        $rootCloneId = BlockPlacement::query()->where('container_type', $host->containerType)->where('container_id', $host->containerId)->first()->block_id;

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->call('openPicker', $rootCloneId, 'content')
            ->mountAction('insertFromTokenAction')
            ->setActionData(['token' => $rootCloneId, 'asCopy' => '1'])
            ->callMountedAction()
            ->assertHasNoErrors();
    }

    /**
     * The copy/reference choice used to be two bare labels ("Копией"/"Ссылкой") with no
     * explanation of what either actually does — reflects into the Action's own protected
     * `schema` array (set as a literal array, not a Closure, so it's readable without a live
     * Schema/Livewire render context — the same reason full modal-HTML assertions are noted as
     * unreliable in this harness elsewhere, see TgsCanvasTokenTabTest's docblock) to confirm
     * the `asCopy` Radio field now carries a description for each option.
     */
    #[Test]
    public function the_copy_vs_reference_radio_explains_what_each_option_does(): void
    {
        $demo = TgsDemo::factory()->create();
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('insertFromTokenAction');

        // @phpstan-ignore method.notFound (Livewire component method, ->instance() is typed as the base Component)
        $action = $component->instance()->getAction('insertFromTokenAction');

        $schemaProperty = new ReflectionProperty($action, 'schema');
        $schemaProperty->setAccessible(true);
        $schema = $schemaProperty->getValue($action);

        $radio = collect($schema)->first(fn ($component) => $component instanceof Radio);

        $this->assertNotNull($radio, 'Expected insertFromTokenAction() to declare an asCopy Radio field.');
        $this->assertSame(
            __('tgs::filament.tgs.canvas.library.insert_as_copy_description'),
            $radio->getDescription(1),
        );
        $this->assertSame(
            __('tgs::filament.tgs.canvas.library.insert_as_reference_description'),
            $radio->getDescription(0),
        );
    }
}
