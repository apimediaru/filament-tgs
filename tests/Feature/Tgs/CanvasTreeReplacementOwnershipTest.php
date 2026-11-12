<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class CanvasTreeReplacementOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private CanvasPersistence $persistence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persistence = app(CanvasPersistence::class);
    }

    #[Test]
    public function new_block_gets_owner_from_the_persisting_host(): void
    {
        $host = new HostDescriptor('news', '1', 'content_builder_value');

        $this->persistence->replaceTree($host, [
            ['type' => 'content', 'data' => ['id' => 'A', 'body' => 'hi']],
        ]);

        $block = Block::find('A');
        $this->assertSame('news', $block->owner_container_type);
        $this->assertSame('1', $block->owner_container_id);
    }

    #[Test]
    public function owning_host_can_update_its_own_block_data(): void
    {
        $host = new HostDescriptor('news', '1', 'content_builder_value');

        $this->persistence->replaceTree($host, [
            ['type' => 'content', 'data' => ['id' => 'A', 'body' => 'old']],
        ]);
        $this->persistence->replaceTree($host, [
            ['type' => 'content', 'data' => ['id' => 'A', 'body' => 'new']],
        ]);

        $this->assertSame('new', Block::find('A')->data['body']);
    }

    #[Test]
    public function non_owning_host_cannot_change_block_data_only_placement(): void
    {
        $owner = new HostDescriptor('news', '1', 'content_builder_value');
        $this->persistence->replaceTree($owner, [
            ['type' => 'content', 'data' => ['id' => 'SHARED', 'body' => 'source-of-truth']],
        ]);

        // Другой хост "sync-вставляет" тот же block_id — но decompose всё равно
        // получает его тело в дереве (draft-путь из под-проекта 2 пока не подключён,
        // здесь проверяется низкоуровневый инвариант decompose напрямую).
        $stranger = new HostDescriptor('page', '7', 'template_data.builder');
        $this->persistence->replaceTree($stranger, [
            ['type' => 'content', 'data' => ['id' => 'SHARED', 'body' => 'tampered-by-stranger']],
        ]);

        $this->assertSame('source-of-truth', Block::find('SHARED')->data['body']);
        $this->assertDatabaseHas('tgs_block_placements', [
            'container_type' => 'page', 'container_id' => '7', 'block_id' => 'SHARED',
        ]);
    }
}
