<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Этап 4, Слайс E (Требование 4.1): удаление контейнерного блока с живыми вложенными детьми
 * называет их число в заголовке подтверждения ("Удалить «Аккордеон» и N вложенных блоков?").
 * Лист/пустой контейнер получают заголовок без изменений (без числа) — как раньше. Дополняет,
 * не заменяет, 3-вариантную choice-модалку шаренных блоков (`TgsCanvasDeleteChoiceTest`).
 */
class TgsCanvasDeleteConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private CanvasPersistence $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CanvasPersistence::class);
    }

    #[Test]
    public function confirmation_names_the_nested_count_for_a_container_with_live_children(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $container = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $container->name = 'Мой контейнер';
        $container->save();
        $this->svc->addBlock($host, 'block', $container->id, 'content', 'content', []);
        $this->svc->addBlock($host, 'block', $container->id, 'content', 'content', []);

        $expectedHeading = trans_choice('tgs::filament.tgs.canvas.delete.container_heading', 2, [
            'name' => 'Мой контейнер',
            'count' => 2,
        ]);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('deleteBlockAction', arguments: ['block' => $container->id, 'parent' => null, 'slot' => null])
            ->assertMountedActionModalSee($expectedHeading);
    }

    #[Test]
    public function nested_count_is_computed_across_multiple_levels_of_the_subtree(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $outer = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $inner = $this->svc->addBlock($host, 'block', $outer->id, 'content', 'column', []);
        $this->svc->addBlock($host, 'block', $inner->id, 'content', 'content', []);
        $this->svc->addBlock($host, 'block', $inner->id, 'content', 'content', []);

        // outer -> inner (1) -> two leaves (2) = 3 live descendants, not counting outer itself.
        $this->assertSame(3, $this->svc->countLiveDescendants($outer->id));
    }

    #[Test]
    public function confirmation_omits_the_nested_count_for_a_leaf_block(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $leaf = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', []);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('deleteBlockAction', arguments: ['block' => $leaf->id, 'parent' => null, 'slot' => null])
            ->assertMountedActionModalSee(__('tgs::filament.tgs.canvas.delete.heading'));

        $this->assertSame(0, $this->svc->countLiveDescendants($leaf->id));
    }

    #[Test]
    public function confirmation_omits_the_nested_count_for_an_empty_container(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $container = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('deleteBlockAction', arguments: ['block' => $container->id, 'parent' => null, 'slot' => null])
            ->assertMountedActionModalSee(__('tgs::filament.tgs.canvas.delete.heading'));

        $this->assertSame(0, $this->svc->countLiveDescendants($container->id));
    }

    #[Test]
    public function shared_and_container_facts_are_both_shown_when_a_block_is_both(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $consumer = HostDescriptor::forModelSlot(TgsDemo::factory()->create(), 'content');

        $container = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $this->svc->addBlock($host, 'block', $container->id, 'content', 'content', []);
        $this->svc->insertReference($consumer, $consumer->containerType, $consumer->containerId, $consumer->slot, $container->id);

        Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])
            ->mountAction('deleteBlockAction', arguments: ['block' => $container->id, 'parent' => null, 'slot' => null])
            // The shared-block choice heading still wins the modal heading (which variant to pick
            // matters most when multiple hosts are affected)...
            ->assertMountedActionModalSee(__('tgs::filament.tgs.canvas.delete.choice.heading'))
            // ...but the nested-count fact is not silently dropped — it's folded into the description.
            ->assertMountedActionModalSee(trans_choice('tgs::filament.tgs.canvas.delete.container_nested_note', 1, ['count' => 1]));
    }

    #[Test]
    public function nested_count_ignores_a_child_that_was_already_deleted_separately(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        $container = $this->svc->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $childA = $this->svc->addBlock($host, 'block', $container->id, 'content', 'content', []);
        $this->svc->addBlock($host, 'block', $container->id, 'content', 'content', []);

        $this->svc->deleteBlock($host, 'block', $container->id, 'content', $childA->id);

        $this->assertSame(1, $this->svc->countLiveDescendants($container->id));
    }
}
