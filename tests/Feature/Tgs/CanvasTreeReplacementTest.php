<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

use function strlen;

class CanvasTreeReplacementTest extends TestCase
{
    use RefreshDatabase;

    private CanvasPersistence $persistence;

    private HostDescriptor $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persistence = app(CanvasPersistence::class);
        $this->host = new HostDescriptor('news', '42', 'content_builder_value');
    }

    #[Test]
    public function persists_blocks_and_root_placements(): void
    {
        $tree = [
            ['type' => 'picture', 'data' => ['id' => 'PIC', 'uuid' => 'm1']],
            ['type' => 'content', 'data' => ['id' => 'TXT', 'body' => 'hi']],
        ];

        $this->persistence->replaceTree($this->host, $tree);

        $this->assertDatabaseHas('tgs_blocks', ['id' => 'PIC', 'type' => 'picture']);
        $this->assertDatabaseHas('tgs_block_placements', [
            'container_type' => 'news', 'container_id' => '42', 'slot' => 'content_builder_value',
            'block_id' => 'PIC',
        ]);
        $this->assertDatabaseHas('tgs_block_placements', [
            'container_type' => 'news', 'container_id' => '42', 'block_id' => 'TXT',
        ]);

        $order = BlockPlacement::query()
            ->where('container_type', 'news')->where('container_id', '42')->where('slot', 'content_builder_value')
            ->orderBy('rank')->pluck('block_id')->all();
        $this->assertSame(['PIC', 'TXT'], $order);
    }

    #[Test]
    public function persists_nested_placements_under_block_container(): void
    {
        $tree = [[
            'type' => 'column',
            'data' => ['id' => 'COL', 'content' => [
                ['type' => 'content', 'data' => ['id' => 'CH', 'body' => 'x']],
            ]],
        ]];

        $this->persistence->replaceTree($this->host, $tree);

        $this->assertDatabaseHas('tgs_block_placements', [
            'container_type' => 'block', 'container_id' => 'COL', 'slot' => 'content',
            'block_id' => 'CH',
        ]);
        $this->assertArrayNotHasKey('content', Block::find('COL')->data);
    }

    #[Test]
    public function reusing_id_preserves_block_identity_and_updates_data(): void
    {
        $this->persistence->replaceTree($this->host, [
            ['type' => 'content', 'data' => ['id' => 'TXT', 'body' => 'old']],
        ]);
        $this->persistence->replaceTree($this->host, [
            ['type' => 'content', 'data' => ['id' => 'TXT', 'body' => 'new']],
        ]);

        $this->assertSame(1, Block::where('id', 'TXT')->count());
        $this->assertSame('new', Block::find('TXT')->data['body']);
    }

    #[Test]
    public function a_newly_created_block_is_committed_not_left_as_a_phantom_draft(): void
    {
        // Старый Builder не знает понятия «черновик» (ADR-020) — блок, созданный этим
        // write-path'ом, тут же получает placement и обязан быть committed_at сразу,
        // иначе TgsCanvas::editBlockAction() ошибочно принимает его за черновик, ожидая
        // аргументы containerType/containerId/slot, которых кнопка «Настройки» узла не
        // передаёт (см. bugfix 2026-07-25).
        $this->persistence->replaceTree($this->host, [
            ['type' => 'content', 'data' => ['id' => 'TXT', 'body' => 'hi']],
        ]);

        $this->assertNotNull(Block::find('TXT')->committed_at);
    }

    #[Test]
    public function re_persist_removes_stale_placements(): void
    {
        $this->persistence->replaceTree($this->host, [
            ['type' => 'content', 'data' => ['id' => 'A', 'body' => '1']],
            ['type' => 'content', 'data' => ['id' => 'B', 'body' => '2']],
        ]);

        $this->persistence->replaceTree($this->host, [
            ['type' => 'content', 'data' => ['id' => 'A', 'body' => '1']],
        ]);

        $this->assertSame(1, BlockPlacement::where('container_id', '42')->count());
        $this->assertDatabaseMissing('tgs_block_placements', ['block_id' => 'B']);
        $this->assertDatabaseHas('tgs_blocks', ['id' => 'B']); // осиротел, но жив (корзина — позже)
    }

    #[Test]
    public function a_failed_replacement_restores_the_previous_tree_and_block_data(): void
    {
        $this->persistence->replaceTree($this->host, [
            ['type' => 'content', 'data' => ['id' => 'A', 'body' => 'original']],
        ]);
        Block::creating(function (Block $block): void {
            if ($block->id === 'INVALID') {
                throw new \RuntimeException('Simulated storage failure');
            }
        });
        try {
            $this->persistence->replaceTree($this->host, [
                ['type' => 'content', 'data' => ['id' => 'A', 'body' => 'changed']],
                ['type' => 'content', 'data' => ['id' => 'INVALID']],
            ]);
            $this->fail('Invalid row must reject the snapshot.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated storage failure', $exception->getMessage());
            $this->assertSame('original', Block::findOrFail('A')->data['body']);
            $this->assertSame(['A'], BlockPlacement::query()->where('container_id', '42')->pluck('block_id')->all());
        }
    }

    #[Test]
    public function snapshot_keeps_item_metadata_and_scalar_lists_and_generates_missing_ids(): void
    {
        $this->persistence->replaceTree($this->host, [[
            'type' => 'accordion', 'data' => ['id' => 'ACC', 'items' => [[
                'title' => 'Question', 'is_open' => false, 'content' => [[
                    'type' => 'gallery', 'data' => ['images' => ['uuid-1', 'uuid-2']],
                ]],
            ]]],
        ]]);
        $this->assertSame(['items' => [['title' => 'Question', 'is_open' => false]]], Block::findOrFail('ACC')->data);
        $child = BlockPlacement::query()->where('container_type', 'block')->where('container_id', 'ACC')->sole();
        $this->assertSame('items.0.content', $child->slot);
        $this->assertSame(26, strlen($child->block_id));
        $this->assertSame(['images' => ['uuid-1', 'uuid-2']], $child->block->data);
    }

    #[Test]
    public function adopting_an_orphan_container_replaces_its_existing_children(): void
    {
        $original = [['type' => 'column', 'data' => ['id' => 'COL', 'content' => [
            ['type' => 'content', 'data' => ['id' => 'CH', 'body' => 'before']],
        ]]]];
        $this->persistence->replaceTree($this->host, $original);
        Block::query()->update(['owner_container_type' => 'orphan', 'owner_container_id' => 'unknown']);
        $original[0]['data']['content'][0]['data']['body'] = 'after';
        $this->persistence->replaceTree($this->host, $original);
        $this->assertSame('after', Block::findOrFail('CH')->data['body']);
        $this->assertSame('news', Block::findOrFail('COL')->owner_container_type);
        $this->assertSame(1, BlockPlacement::query()->where('container_type', 'block')->where('container_id', 'COL')->count());
    }
}
