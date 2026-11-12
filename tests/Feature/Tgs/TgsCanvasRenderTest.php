<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsCanvasRenderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_mounts_on_a_group_and_renders_its_blocks(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->content(['content' => 'Видимый текст'])->withId('RENDER-1')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'RENDER-1']);

        Livewire::test(TgsCanvas::class, ['canvasId' => $group->id])
            ->assertSuccessful()
            ->assertSee('Видимый текст');
    }

    #[Test]
    public function it_uses_path_qualified_wire_keys_when_a_block_is_reused_in_two_slots(): void
    {
        // CanvasTreeReader (Task 1) намеренно допускает один и тот же id блока в двух
        // независимых слотах (reuse, plan §10) — wire:key обязан быть path-qualified,
        // иначе Livewire/Alpine схлопнут diff по двум узлам с одинаковым ключом.
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();
        $own = ['owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId];

        Block::factory()->column()->withId('COL-A')->create($own);
        Block::factory()->column()->withId('COL-B')->create($own);
        Block::factory()->content(['content' => 'Общий текст'])->withId('SHARED')->create($own);

        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'COL-A']);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000001', 'block_id' => 'COL-B']);
        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => 'COL-A',
            'slot' => 'content', 'rank' => '000000', 'block_id' => 'SHARED']);
        BlockPlacement::forceCreate(['container_type' => 'block', 'container_id' => 'COL-B',
            'slot' => 'content', 'rank' => '000000', 'block_id' => 'SHARED']);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->assertSuccessful();
        $html = $component->html();

        // Общий блок отрендерен дважды (в обоих слотах) — и каждый раз превью-текст
        // присутствует в разметке ДВАЖДЫ (Stage 2: инлайн-версия для широкого контейнера +
        // versión data-testid="preview-block" для узкого, переключаются через `@container`,
        // без JS/перестановки DOM) — итого 2 × 2 = 4.
        $this->assertSame(4, substr_count($html, 'Общий текст'));

        preg_match_all('/wire:key="(node-[^"]+)"/', $html, $matches);
        $keys = $matches[1];

        $this->assertGreaterThanOrEqual(4, count($keys), 'ожидались ключи для обеих колонок и обоих вхождений shared-блока');
        $this->assertSame(count($keys), count(array_unique($keys)), 'wire:key не должны дублироваться при переиспользовании блока в разных слотах');
    }

    #[Test]
    public function the_edit_block_button_renders_a_clean_mount_action_call_not_a_leaked_button_html(): void
    {
        // ($this->editBlockAction)([...]) возвращает клон Action (HasMountableArguments),
        // а не строку. Action — Htmlable (ViewComponent::toHtml() рендерит полный <button>),
        // поэтому Blade {{ ... }} вставляет ЦЕЛУЮ HTML-разметку кнопки внутрь значения
        // атрибута wire:click="..." родительской кнопки — кавычки внутри рвут атрибут,
        // и хвост (class="...", title="Настройки", label "Edit block action") утекает
        // в видимый текст страницы. Правильный вызов — mountAction('name', arguments).
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        Block::factory()->content(['content' => 'Текст'])->withId('LEAK-1')->create([
            'owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId]);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'LEAK-1']);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->assertSuccessful();
        $html = $component->html();

        $this->assertStringNotContainsString('Edit block action', $html, 'лейбл Action не должен утекать в видимый текст');
        $this->assertMatchesRegularExpression(
            '/wire:click="mountAction\(\'editBlockAction\'/',
            $html,
            'кнопка настроек должна вызывать mountAction(...), а не рендерить весь Action как HTML',
        );
    }

    #[Test]
    public function it_shows_the_block_name_badge_only_when_a_name_is_set(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();
        $own = ['owner_container_type' => $host->containerType, 'owner_container_id' => $host->containerId];

        Block::factory()->content(['content' => 'Текст с именем'])->withId('NAMED-1')->create(['name' => 'Мой именованный блок', ...$own]);
        Block::factory()->content(['content' => 'Текст без имени'])->withId('UNNAMED-1')->create($own);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000000', 'block_id' => 'NAMED-1']);
        BlockPlacement::forceCreate(['container_type' => $host->containerType, 'container_id' => $host->containerId,
            'slot' => $host->slot, 'rank' => '000001', 'block_id' => 'UNNAMED-1']);

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->assertSuccessful();
        $html = $component->html();

        $this->assertStringContainsString('Мой именованный блок', $html);
        $this->assertSame(1, substr_count($html, 'data-testid="node-name"'), 'бейдж имени должен рендериться ровно один раз — только для именованного блока');
    }
}
