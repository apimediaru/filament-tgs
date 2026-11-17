<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Data\Blocks\CodeData;
use ApiMedia\Tgs\Livewire\OrphanedBlocksTable;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\TgsCommonSettings;
use ApiMedia\Tgs\Support\TgsConfiguration;
use ApiMedia\Tgs\Tests\Fixtures\CommonSettings\SiteSettings;
use ApiMedia\Tgs\Tests\TgsUiTestCase;
use ApiMedia\Tgs\TgsPlugin;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class BlockEditDataTest extends TgsUiTestCase
{
    use RefreshDatabase;

    public static function editors(): array
    {
        return [['content', true], ['code', true], ['content', false], ['code', false]];
    }

    #[Test]
    #[DataProvider('editors')]
    public function hidden_and_unknown_saved_values_survive_but_client_injections_do_not(string $type, bool $orphan): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(HiddenHeadingSettings::class));
        $old = [$type => 'Old', 'section_heading' => 'Keep', 'container_size' => 'lg', 'disabled_extension' => ['saved' => true]];
        if ($orphan) {
            $block = Block::factory()->orphan()->create(['type' => $type, 'data' => $old]);
            $editor = Livewire::test(OrphanedBlocksTable::class);
        } else {
            $canvas = TgsDemo::factory()->create()->canvas('content');
            $host = $canvas->hostDescriptor();
            $block = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, $type, $old);
            $editor = Livewire::test(TgsCanvas::class, ['canvasId' => $canvas->id]);
        }
        $editor->callAction('editBlockAction', data: [$type => 'New', 'section_heading' => 'ATTACK', 'unknown_new' => 'ATTACK'], arguments: ['block' => $block->id, 'type' => $type, 'isRoot' => false])->assertHasNoErrors();
        $data = $block->fresh()->data;
        $this->assertStringContainsString('New', $data[$type]);
        $this->assertSame('Keep', $data['section_heading'] ?? null);
        $this->assertSame(['saved' => true], $data['disabled_extension'] ?? null);
        $this->assertArrayNotHasKey('unknown_new', $data);
        if (! $orphan) {
            $this->assertSame('lg', $data['container_size'] ?? null);
        }
    }

    private function editorFor(string $type, bool $orphan, array $data): array
    {
        if ($orphan) {
            $block = Block::factory()->orphan()->create(['type' => $type, 'data' => $data]);
            $editor = Livewire::test(OrphanedBlocksTable::class);
        } else {
            $canvas = TgsDemo::factory()->create()->canvas('content');
            $host = $canvas->hostDescriptor();
            $block = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, $type, $data);
            $editor = Livewire::test(TgsCanvas::class, ['canvasId' => $canvas->id]);
        }

        return [$block, $editor->mountAction('editBlockAction', ['block' => $block->id, 'type' => $type, 'isRoot' => true])];
    }

    #[Test]
    #[DataProvider('editors')]
    public function reorder_keeps_hidden_values_attached_to_their_items(string $type, bool $orphan): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(RepeaterSettings::class));
        $rows = [['label' => 'First', 'secret' => 'A'], ['label' => 'Second', 'secret' => 'B'], ['label' => 'Third', 'secret' => 'C']];
        [$block, $editor] = $this->editorFor($type, $orphan, [$type => 'Old', 'section_items' => $rows]);
        $state = $editor->get('mountedActions.0.data.section_items');
        $keys = array_keys($state);
        $editor->set('mountedActions.0.data.section_items', [
            $keys[2] => ['label' => 'Third edited', 'secret' => 'INJECTED'],
            $keys[0] => ['label' => 'First edited'],
            $keys[1] => ['label' => 'Second edited'],
        ])->callMountedAction()->assertHasNoErrors();
        $expected = [['label' => 'Third edited', 'secret' => 'C'], ['label' => 'First edited', 'secret' => 'A'], ['label' => 'Second edited', 'secret' => 'B']];
        $this->assertSame($expected, $block->fresh()->data['section_items']);
        $editor->mountAction('editBlockAction', ['block' => $block->id, 'type' => $type, 'isRoot' => true]);
        $this->assertSame($expected, array_values($editor->get('mountedActions.0.data.section_items')));
    }

    #[Test]
    #[DataProvider('editors')]
    public function nested_repeaters_preserve_identity_through_reorder_delete_and_insert(string $type, bool $orphan): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(NestedRepeaterSettings::class));
        [$block, $editor] = $this->editorFor($type, $orphan, [$type => 'Old', 'section_items' => [
            ['label' => 'Parent A', 'secret' => 'A', 'children' => [['label' => 'A1', 'secret' => 'A1'], ['label' => 'A2', 'secret' => 'A2']]],
            ['label' => 'Parent B', 'secret' => 'B', 'children' => [['label' => 'B1', 'secret' => 'B1'], ['label' => 'B2', 'secret' => 'B2']]],
        ]]);
        $state = $editor->get('mountedActions.0.data.section_items');
        [$a, $b] = array_keys($state);
        [$a1, $a2] = array_keys($state[$a]['children']);
        [$b1, $b2] = array_keys($state[$b]['children']);
        $editor->set('mountedActions.0.data.section_items', [
            $b => ['label' => 'Parent B', 'children' => [
                $b2 => ['label' => 'B2 edited', 'secret' => 'INJECTED'],
                $b1 => ['label' => 'B1 edited'],
            ]],
            $a => ['label' => 'Parent A', 'children' => [
                $a2 => ['label' => 'A2 edited'],
                'new-item' => ['label' => 'New', 'secret' => 'INJECTED'],
            ]],
        ])->callMountedAction()->assertHasNoErrors();
        $this->assertSame([
            ['label' => 'Parent B', 'secret' => 'B', 'children' => [['label' => 'B2 edited', 'secret' => 'B2'], ['label' => 'B1 edited', 'secret' => 'B1']]],
            ['label' => 'Parent A', 'secret' => 'A', 'children' => [['label' => 'A2 edited', 'secret' => 'A2'], ['label' => 'New']]],
        ], $block->fresh()->data['section_items']);
    }

    #[Test]
    #[DataProvider('editors')]
    public function visibility_at_save_controls_writes_and_validation_in_both_directions(string $type, bool $orphan): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(ConditionalSettings::class));
        [$block, $editor] = $this->editorFor($type, $orphan, [$type => 'Old', 'section_show' => true, 'section_detail' => 'legacy']);
        $editor->set('mountedActions.0.data.section_detail', 'INJECTED')
            ->set('mountedActions.0.data.section_show', false)->callMountedAction()->assertHasNoErrors();
        $this->assertSame('legacy', $block->fresh()->data['section_detail']);
        $this->assertFalse($block->fresh()->data['section_show']);
        $editor->mountAction('editBlockAction', ['block' => $block->id, 'type' => $type, 'isRoot' => true])
            ->set('mountedActions.0.data.section_show', true)->callMountedAction()->assertHasActionErrors(['section_detail']);
        $this->assertSame('legacy', $block->fresh()->data['section_detail']);
        $editor->set('mountedActions.0.data.section_detail', 'allowed')->callMountedAction()->assertHasNoErrors();
        $this->assertSame('allowed', $block->fresh()->data['section_detail']);
        $this->assertTrue($block->fresh()->data['section_show']);
    }

    #[Test]
    public function removing_and_reordering_repeater_items_preserves_only_their_own_hidden_values(): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(RepeaterSettings::class));
        $block = Block::factory()->orphan()->create(['type' => 'content', 'data' => ['content' => 'Old', 'section_items' => [
            ['label' => 'First', 'secret' => 'A'], ['label' => 'Second', 'secret' => 'B'],
        ]]]);
        $editor = Livewire::test(OrphanedBlocksTable::class)->mountAction('editBlockAction', ['block' => $block->id, 'type' => 'content']);
        $items = $editor->get('mountedActions.0.data.section_items');
        $keys = array_keys($items);
        $editor->set('mountedActions.0.data.section_items', [$keys[1] => ['label' => 'New', 'secret' => 'ATTACK', 'evil' => 'ATTACK']])
            ->callMountedAction()->assertHasNoErrors();
        $this->assertSame([['label' => 'New', 'secret' => 'B']], $block->fresh()->data['section_items']);
        $editor->mountAction('editBlockAction', ['block' => $block->id, 'type' => 'content'])
            ->set('mountedActions.0.data.section_items', [])->callMountedAction()->assertHasNoErrors();
        $this->assertSame([], $block->fresh()->data['section_items']);
    }

    #[Test]
    public function hiding_a_parent_group_keeps_its_invalid_saved_children(): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(HiddenGroupSettings::class));
        $block = Block::factory()->orphan()->create(['type' => 'code', 'data' => ['code' => 'Old', 'section_style' => ['tone' => 'legacy']]]);
        Livewire::test(OrphanedBlocksTable::class)->callAction('editBlockAction', data: [
            'code' => 'New', 'section_style' => ['tone' => 'ATTACK']],
            arguments: ['block' => $block->id, 'type' => 'code'])->assertHasNoErrors();
        $this->assertSame(['tone' => 'legacy'], $block->fresh()->data['section_style']);
    }

    #[Test]
    public function explicit_null_clears_a_visible_field_and_nested_hidden_values_survive(): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(NestedSettings::class));
        $block = Block::factory()->orphan()->create(['type' => 'code', 'data' => ['code' => 'Old', 'section_heading' => 'Old heading',
            'section_style' => ['tone' => 'Old tone', 'secret' => 'Keep', 'unknown' => 'Keep too']]]);
        Livewire::test(OrphanedBlocksTable::class)->callAction('editBlockAction', data: [
            'code' => 'New', 'section_heading' => null, 'section_style' => ['tone' => 'New tone', 'secret' => 'ATTACK', 'new' => 'ATTACK']],
            arguments: ['block' => $block->id, 'type' => 'code'])->assertHasNoErrors();
        $this->assertNull($block->fresh()->data['section_heading']);
        $this->assertSame(['tone' => 'New tone', 'secret' => 'Keep', 'unknown' => 'Keep too'], $block->fresh()->data['section_style']);
    }

    #[Test]
    public function new_validation_rejects_visible_legacy_values_but_ignores_disabled_fields(): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(ValidationSettings::class));
        $block = Block::factory()->orphan()->create(['type' => 'code', 'data' => ['code' => 'Old', 'section_heading' => 'legacy', 'section_bg' => 'legacy']]);
        $editor = Livewire::test(OrphanedBlocksTable::class)->mountAction('editBlockAction', ['block' => $block->id, 'type' => 'code']);
        $editor->callMountedAction()->assertHasActionErrors(['section_heading']);
        $this->assertSame('legacy', $block->fresh()->data['section_heading']);
        $editor->set('mountedActions.0.data.section_heading', 'allowed')->callMountedAction()->assertHasNoErrors();
        $this->assertSame('allowed', $block->fresh()->data['section_heading']);
        $this->assertSame('legacy', $block->fresh()->data['section_bg']);
    }

    #[Test]
    public function disabling_and_reenabling_the_extension_preserves_data_through_an_intervening_edit(): void
    {
        $config = app(TgsConfiguration::class);
        $config->register('site', TgsPlugin::make()->commonSettings(SiteSettings::class));
        $block = Block::factory()->orphan()->create(['type' => 'code', 'data' => ['code' => 'Old', 'section_note' => 'Keep', 'section_style' => ['tone' => 'calm']]]);
        $config->register('site', TgsPlugin::make());
        Livewire::test(OrphanedBlocksTable::class)->callAction('editBlockAction', data: ['code' => 'New'], arguments: ['block' => $block->id, 'type' => 'code'])->assertHasNoErrors();
        $raw = $block->fresh()->data;
        $this->assertSame('Keep', $raw['section_note']);
        $this->assertArrayNotHasKey('section_note', CodeData::fromStored($raw, new TgsDemo)->toArray()['section']);
        $config->register('site', TgsPlugin::make()->commonSettings(SiteSettings::class));
        $this->assertSame('Keep', CodeData::fromStored($raw, new TgsDemo)->toArray()['section']['section_note']);
    }
}
class HiddenHeadingSettings extends TgsCommonSettings
{
    public function heading(array $fields): array
    {
        return [TextInput::make('section_heading')->hidden()->required()->in(['forbidden'])];
    }
}

class RepeaterSettings extends TgsCommonSettings
{
    public function section(array $fields): array
    {
        return [...$fields, Repeater::make('section_items')->schema([
            TextInput::make('label'), TextInput::make('secret')->hidden(),
        ])];
    }
}
class NestedSettings extends TgsCommonSettings
{
    public function section(array $fields): array
    {
        return [...$fields, Group::make([
            TextInput::make('tone'), TextInput::make('secret')->hidden(),
        ])->statePath('section_style')];
    }
}

class ValidationSettings extends TgsCommonSettings
{
    public function heading(array $fields): array
    {
        return [TextInput::make('section_heading')->required()->in(['allowed'])];
    }

    public function section(array $fields): array
    {
        return [TextInput::make('section_bg')->disabled()->required()->in(['allowed'])];
    }
}

class HiddenGroupSettings extends TgsCommonSettings
{
    public function section(array $fields): array
    {
        return [...$fields, Group::make([TextInput::make('tone')->required()->in(['allowed'])])->statePath('section_style')->hidden()];
    }
}

class NestedRepeaterSettings extends TgsCommonSettings
{
    public function section(array $fields): array
    {
        return [...$fields, Repeater::make('section_items')->schema([
            TextInput::make('label'), TextInput::make('secret')->hidden(),
            Repeater::make('children')->schema([TextInput::make('label'), TextInput::make('secret')->hidden()]),
        ])];
    }
}
class ConditionalSettings extends TgsCommonSettings
{
    public function section(array $fields): array
    {
        return [...$fields, Toggle::make('section_show')->live(),
            TextInput::make('section_detail')->required()->in(['allowed'])
                ->hidden(fn (Get $get): bool => ! $get('section_show')),
        ];
    }
}
