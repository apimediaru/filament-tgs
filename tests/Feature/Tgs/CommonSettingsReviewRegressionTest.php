<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Blocks\Code;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Livewire\OrphanedBlocksTable;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\BlockVersion;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\TgsBlockRegistry;
use ApiMedia\Tgs\Support\TgsCommonSettings;
use ApiMedia\Tgs\Support\TgsConfiguration;
use ApiMedia\Tgs\Tests\TgsUiTestCase;
use ApiMedia\Tgs\TgsPlugin;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Workbench\App\Models\TgsDemo;

class CommonSettingsReviewRegressionTest extends TgsUiTestCase
{
    use RefreshDatabase;

    public static function editors(): array
    {
        return [[true], [false]];
    }

    private function editor(bool $orphan, array $data): array
    {
        if ($orphan) {
            return [Block::factory()->orphan()->create(['type' => 'code', 'data' => $data]), Livewire::test(OrphanedBlocksTable::class)];
        }
        $canvas = TgsDemo::factory()->create()->canvas('content');
        $host = $canvas->hostDescriptor();

        return [app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'code', $data), Livewire::test(TgsCanvas::class, ['canvasId' => $canvas->id])];
    }

    #[Test]
    #[DataProvider('editors')]
    public function client_type_cannot_unhide_the_real_blocks_field(bool $orphan): void
    {
        app(TgsBlockRegistry::class)->blocks([ReviewRestrictedCode::class]);
        [$block,$editor] = $this->editor($orphan, ['code' => 'old', 'section_heading' => 'keep']);
        $editor->callAction('editBlockAction', data: ['code' => 'new', 'content' => 'spoofed type', 'section_heading' => 'injected'], arguments: ['block' => $block->id, 'type' => 'content', 'isRoot' => true])->assertHasNoErrors();
        $this->assertSame('keep', $block->fresh()->data['section_heading']);
        $this->assertSame('new', $block->fresh()->data['code']);
        $this->assertArrayNotHasKey('content', $block->fresh()->data);
    }

    #[Test]
    public function a_nested_block_cannot_enable_root_fields_with_a_client_flag(): void
    {
        $canvas = TgsDemo::factory()->create()->canvas('content');
        $host = $canvas->hostDescriptor();
        $persistence = app(CanvasPersistence::class);
        $parent = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $block = $persistence->addBlock($host, 'block', $parent->id, 'content', 'code', ['code' => 'old', 'container_size' => 'lg']);
        Livewire::test(TgsCanvas::class, ['canvasId' => $canvas->id])->callAction('editBlockAction',
            data: ['code' => 'new', 'container_size' => 'sm'], arguments: ['block' => $block->id, 'type' => 'code', 'isRoot' => true])->assertHasNoErrors();
        $this->assertSame('lg', $block->fresh()->data['container_size']);
        $this->assertSame('new', $block->fresh()->data['code']);
    }

    #[Test]
    public function a_nested_draft_cannot_forge_its_root_context_or_destination(): void
    {
        $canvas = TgsDemo::factory()->create()->canvas('content');
        $host = $canvas->hostDescriptor();
        $parent = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $editor = Livewire::test(TgsCanvas::class, ['canvasId' => $canvas->id])->call('addChildBlock', $parent->id, 'content', 'code');
        $blockId = $editor->get('draftBlockId');
        $editor->set('mountedActions.0.arguments.isRoot', true)
            ->set('mountedActions.0.arguments.containerType', $host->containerType)
            ->set('mountedActions.0.arguments.containerId', $host->containerId)
            ->fillForm(['code' => 'new', 'container_size' => 'sm'])->callMountedAction()->assertHasNoErrors();
        $block = Block::findOrFail($blockId);
        $this->assertNotNull($block->committed_at);
        $this->assertArrayNotHasKey('container_size', $block->data);
        $placement = BlockPlacement::where('block_id', $blockId)->sole();
        $this->assertSame('block', $placement->container_type);
        $this->assertSame($parent->id, $placement->container_id);
    }

    #[Test]
    public function a_root_draft_can_still_save_its_container_settings(): void
    {
        $canvas = TgsDemo::factory()->create()->canvas('content');
        $editor = Livewire::test(TgsCanvas::class, ['canvasId' => $canvas->id])->call('addRootBlock', 'code');
        $blockId = $editor->get('draftBlockId');
        $editor->fillForm(['code' => 'new', 'container_size' => 'sm'])->callMountedAction()->assertHasNoErrors();
        $this->assertSame('sm', Block::findOrFail($blockId)->data['container_size']);
    }

    public static function conflictingEdits(): array
    {
        return [[true, 'hidden'], [false, 'hidden'], [true, 'delete'], [false, 'delete'], [true, 'reorder'], [false, 'reorder'], [true, 'insert'], [false, 'insert']];
    }

    #[Test]
    #[DataProvider('conflictingEdits')]
    public function stale_repeater_edits_are_rejected_without_partial_writes(bool $orphan, string $change): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(ReviewRecursiveSettings::class));
        $rows = $change === 'insert' ? [] : [['label' => 'first', 'secret' => 'original', 'children' => []], ['label' => 'second', 'secret' => 'second', 'children' => []]];
        [$block,$editor] = $this->editor($orphan, ['code' => 'old', 'section_items' => $rows]);
        $arguments = ['block' => $block->id, 'type' => 'code', 'isRoot' => true];
        $editor->mountAction('editBlockAction', $arguments);
        $fresh = $block->fresh()->data;
        match ($change) {
            'hidden' => $fresh['section_items'][0]['secret'] = 'updated by another editor',
            'delete' => $fresh['section_items'] = [],
            'reorder' => $fresh['section_items'] = array_reverse($rows),
            'insert' => $fresh['section_items'] = [['label' => 'new elsewhere', 'children' => []]],
            default => throw new \LogicException('Unknown test mutation'),
        };
        $block->update(['data' => $fresh]);
        $versions = BlockVersion::where('block_id', $block->id)->count();
        $editor->set('mountedActions.0.data.code', 'must not save')->callMountedAction()->assertHasActionErrors(['section_items']);
        $this->assertSame($fresh, $block->fresh()->data);
        $this->assertSame($versions, BlockVersion::where('block_id', $block->id)->count());
        $editor->call('unmountAction')->mountAction('editBlockAction', $arguments)
            ->set('mountedActions.0.data.code', 'saved after reopening')->callMountedAction()->assertHasNoErrors();
        $this->assertSame('saved after reopening', $block->fresh()->data['code']);
        $this->assertSame($fresh['section_items'], $block->fresh()->data['section_items']);
    }

    #[Test]
    #[DataProvider('editors')]
    public function changes_during_form_hydration_are_rejected_without_partial_writes(bool $orphan): void
    {
        $original = ['code' => 'old', 'section_items' => [
            ['label' => 'first', 'secret' => 'first secret', 'children' => [['label' => 'child', 'secret' => 'child secret']]],
            ['label' => 'second', 'secret' => 'second secret', 'children' => []],
        ]];
        [$block, $editor] = $this->editor($orphan, $original);
        $changed = $original;
        $changed['section_items'] = array_reverse($original['section_items']);
        $changed['section_items'][0]['label'] = 'updated elsewhere';
        $changed['section_items'][0]['secret'] = 'updated secret';
        $changedDuringHydration = false;
        $settings = new class(function () use ($block, $changed, &$changedDuringHydration): void {
            if (! $changedDuringHydration) {
                $changedDuringHydration = true;
                $block->update(['data' => $changed]);
            }
        }) extends ReviewRecursiveSettings
        {

            public function __construct(private \Closure $duringHydration) {}

            public function section(array $fields): array
            {
                return [...$fields, Repeater::make('section_items')->schema([
                    TextInput::make('label')->afterStateHydrated($this->duringHydration),
                    Repeater::make('children')->schema([TextInput::make('label')]),
                ])];
            }
        };
        app()->instance(TgsCommonSettings::class, $settings);
        $arguments = ['block' => $block->id, 'type' => 'code', 'isRoot' => true];
        $editor->mountAction('editBlockAction', $arguments);
        $this->assertTrue($changedDuringHydration);
        $state = array_values($editor->get('mountedActions.0.data.section_items'));
        $this->assertSame('first', $state[0]['label']);
        $versions = BlockVersion::where('block_id', $block->id)->count();
        $editor->set('mountedActions.0.data.code', 'must not save')->callMountedAction();
        $this->assertSame($changed, $block->fresh()->data);
        $editor->assertHasActionErrors(['section_items']);
        $this->assertSame($versions, BlockVersion::where('block_id', $block->id)->count());
        $editor->call('unmountAction')->mountAction('editBlockAction', $arguments)
            ->set('mountedActions.0.data.code', 'saved after reopening')->callMountedAction()->assertHasNoErrors();
        $this->assertSame('saved after reopening', $block->fresh()->data['code']);
        $this->assertSame($changed['section_items'], $block->fresh()->data['section_items']);
    }

    #[Test]
    #[DataProvider('editors')]
    public function unrelated_saved_fields_do_not_conflict_with_the_repeater(bool $orphan): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(ReviewRecursiveSettings::class));
        [$block,$editor] = $this->editor($orphan, ['code' => 'old', 'section_items' => [['label' => 'first', 'children' => []]]]);
        $editor->mountAction('editBlockAction', ['block' => $block->id, 'type' => 'code', 'isRoot' => true]);
        $fresh = $block->fresh()->data;
        $fresh['unknown_saved'] = 'new elsewhere';
        $block->update(['data' => $fresh]);
        $editor->set('mountedActions.0.data.code', 'new')->callMountedAction()->assertHasNoErrors();
        $this->assertSame('new elsewhere', $block->fresh()->data['unknown_saved']);
        $this->assertSame('new', $block->fresh()->data['code']);
    }

    #[Test]
    public function finite_nested_paths_accept_a_recursive_dto_but_reject_missing_leaves(): void
    {
        $config = app(TgsConfiguration::class);
        $config->register('site', TgsPlugin::make()->commonSettings(ReviewRecursiveSettings::class));
        $this->assertSame(0, Artisan::call('tgs:check'), Artisan::output());
        $config->register('site', TgsPlugin::make()->commonSettings(ReviewMissingLeafSettings::class));
        $this->artisan('tgs:check')->expectsOutputToContain('section_items.*.children.*.missing')->assertFailed();
    }
}

class ReviewRestrictedCode extends Code
{
    public function getBlockHeadingFields(): array|\Closure
    {
        return [TextInput::make('section_heading')->hidden()];
    }
}
class ReviewRecursiveSettings extends TgsCommonSettings
{
    public static function dataClass(): string
    {
        return ReviewRecursiveSection::class;
    }

    public function section(array $fields): array
    {
        return [...$fields, Repeater::make('section_items')->schema([TextInput::make('label'), Repeater::make('children')->schema([TextInput::make('label')])])];
    }
}
class ReviewMissingLeafSettings extends ReviewRecursiveSettings
{
    public function section(array $fields): array
    {
        return [...$fields, Repeater::make('section_items')->schema([Repeater::make('children')->schema([TextInput::make('missing')])])];
    }
}
#[TypeScript]
class ReviewRecursiveSection extends TgsSectionData
{
    /** @var list<ReviewRecursiveItem> */
    public array $section_items = [];
}
#[TypeScript]
class ReviewRecursiveItem extends Data
{
    public ?string $label = null;

    /** @var list<ReviewRecursiveItem> */
    public array $children = [];
}
