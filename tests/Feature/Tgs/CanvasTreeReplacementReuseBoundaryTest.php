<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class CanvasTreeReplacementReuseBoundaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function non_owning_host_referencing_a_container_block_does_not_disturb_its_children(): void
    {
        $owner = new HostDescriptor('news', '1', 'content_builder_value');
        $persistence = app(CanvasPersistence::class);

        // X создаёт составной блок COL с ребёнком CH — оба принадлежат X.
        $persistence->replaceTree($owner, [
            ['type' => 'column', 'data' => ['id' => 'COL', 'content' => [
                ['type' => 'content', 'data' => ['id' => 'CH', 'body' => 'original']],
            ]]],
        ]);

        $this->assertSame('news', Block::find('COL')->owner_container_type);
        $this->assertSame('news', Block::find('CH')->owner_container_type);

        // Y ссылается на COL в своём дереве — но с устаревшим снимком его ребёнка
        // (как если бы форма Y была открыта до последней правки X).
        $referrer = new HostDescriptor('page', '7', 'template_data.builder');
        $persistence->replaceTree($referrer, [
            ['type' => 'column', 'data' => ['id' => 'COL', 'content' => [
                ['type' => 'content', 'data' => ['id' => 'CH', 'body' => 'STALE-FROM-Y']],
            ]]],
        ]);

        // Данные и структура CH принадлежат X — сохранение Y не должно их менять.
        $this->assertSame('original', Block::find('CH')->data['body']);
        $this->assertSame('news', Block::find('CH')->owner_container_type);

        $this->assertDatabaseHas('tgs_block_placements', [
            'container_type' => 'block',
            'container_id' => 'COL',
            'block_id' => 'CH',
        ]);
        $this->assertSame(
            1,
            BlockPlacement::query()->where('container_type', 'block')->where('container_id', 'COL')->count(),
        );

        // Y корректно ссылается на COL в своём собственном корне.
        $this->assertDatabaseHas('tgs_block_placements', [
            'container_type' => 'page',
            'container_id' => '7',
            'slot' => 'template_data.builder',
            'block_id' => 'COL',
        ]);
    }

    #[Test]
    public function owner_can_still_edit_its_own_container_block_children_after_being_referenced(): void
    {
        $owner = new HostDescriptor('news', '1', 'content_builder_value');
        $persistence = app(CanvasPersistence::class);

        $persistence->replaceTree($owner, [
            ['type' => 'column', 'data' => ['id' => 'COL', 'content' => [
                ['type' => 'content', 'data' => ['id' => 'CH', 'body' => 'v1']],
            ]]],
        ]);

        $referrer = new HostDescriptor('page', '7', 'template_data.builder');
        $persistence->replaceTree($referrer, [
            ['type' => 'column', 'data' => ['id' => 'COL', 'content' => [
                ['type' => 'content', 'data' => ['id' => 'CH', 'body' => 'v1']],
            ]]],
        ]);

        // X пересохраняет свой блок с изменённым ребёнком — это должно пройти нормально.
        $persistence->replaceTree($owner, [
            ['type' => 'column', 'data' => ['id' => 'COL', 'content' => [
                ['type' => 'content', 'data' => ['id' => 'CH', 'body' => 'v2']],
            ]]],
        ]);

        $this->assertSame('v2', Block::find('CH')->data['body']);
    }
}
