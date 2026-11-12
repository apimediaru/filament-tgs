<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Filament\ContentBuilderFactory;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\BlockChildPolicy;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasAddPickerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function root_picker_returns_every_registered_block_type_except_child_only(): void
    {
        $group = $this->makeGroup();

        /** @var TgsCanvas $component */
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->instance();
        $result = $component->availableBlocksFor(null, null);

        $expectedTypes = array_map(
            fn (string $class): string => $class::blockType()->value,
            array_values(array_filter(
                ContentBuilderFactory::blockClasses(),
                static fn (string $class): bool => ! $class::make()->isChildOnlyBlock(),
            )),
        );

        $this->assertEqualsCanonicalizing(
            $expectedTypes,
            array_column($result, 'type'),
        );

        foreach ($result as $item) {
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('icon', $item);
            $this->assertArrayHasKey('category', $item);
        }
    }

    #[Test]
    public function unrestricted_slot_returns_every_registered_block_type_except_child_only(): void
    {
        $group = $this->makeGroup();

        // 'column' declares a 'content' slot but no allowedChildren restriction
        // (BlockChildPolicy::allowedChildrenFor() returns [] = unrestricted). Unrestricted
        // still excludes child-only blocks (e.g. tab_item/accordion_item) — only an explicit
        // allowedChildren allowlist can include them.
        /** @var TgsCanvas $component */
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->instance();
        $result = $component->availableBlocksFor('column', 'content');

        $expectedTypes = array_map(
            fn (string $class): string => $class::blockType()->value,
            array_values(array_filter(
                ContentBuilderFactory::blockClasses(),
                static fn (string $class): bool => ! $class::make()->isChildOnlyBlock(),
            )),
        );

        $this->assertEqualsCanonicalizing($expectedTypes, array_column($result, 'type'));
    }

    #[Test]
    public function restricted_slot_returns_only_allowed_children(): void
    {
        $group = $this->makeGroup();

        // Bind a mock BlockChildPolicy that restricts children to 'content' type only
        app()->instance(
            BlockChildPolicy::class,
            new class extends BlockChildPolicy
            {
                public function allowedChildrenFor(TgsBlock $container, string $slot): array
                {
                    return ['content'];
                }
            },
        );

        /** @var TgsCanvas $component */
        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])->instance();
        $result = $component->availableBlocksFor('column', 'content');

        // Assert the result contains only the 'content' type
        $this->assertCount(1, $result);
        $this->assertSame('content', $result[0]['type']);
    }

    private function makeGroup(): Canvas
    {
        $demo = TgsDemo::factory()->create();

        return $demo->canvas('content');
    }
}
