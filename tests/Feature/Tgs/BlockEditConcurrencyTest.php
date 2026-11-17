<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\OrphanedBlocksTable;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Tests\TgsUiTestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class BlockEditConcurrencyTest extends TgsUiTestCase
{
    // Fixtures must be committed so the independent PostgreSQL connection can see them.
    use DatabaseMigrations;

    protected function defineDatabaseMigrations(): void
    {
        app('migrator')->path(\Orchestra\Testbench\default_migration_path());
        app('migrator')->path(dirname(__DIR__, 3).'/workbench/database/migrations');
    }

    public static function editors(): array
    {
        return [[true], [false]];
    }

    #[Test]
    #[DataProvider('editors')]
    public function a_second_connection_cannot_write_between_the_locked_read_and_save(bool $orphan): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL row locks and an independent connection.');
        }
        if ($orphan) {
            $block = Block::factory()->orphan()->create(['type' => 'code', 'data' => ['code' => 'old']]);
            $editor = Livewire::test(OrphanedBlocksTable::class);
        } else {
            $canvas = TgsDemo::factory()->create()->canvas('content');
            $host = $canvas->hostDescriptor();
            $block = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'code', ['code' => 'old']);
            $editor = Livewire::test(TgsCanvas::class, ['canvasId' => $canvas->id]);
        }
        config(['database.connections.r123_competitor' => DB::connection()->getConfig()]);
        $competitor = DB::connection('r123_competitor');
        $competitor->statement("SET lock_timeout = '100ms'");
        $attempted = false;
        $blocked = false;
        DB::listen(function (QueryExecuted $query) use (&$attempted, &$blocked, $competitor, $block): void {
            if ($attempted || ! str_contains($query->sql, 'for update') || ! str_contains($query->sql, 'tgs_blocks')) {
                return;
            }
            $attempted = true;
            try {
                $competitor->table('tgs_blocks')->where('id', $block->id)->update(['data' => json_encode(['code' => 'interleaved'])]);
            } catch (QueryException $e) {
                if ($e->getCode() !== '55P03') {
                    throw $e;
                }
                $blocked = true;
            }
        });
        try {
            $editor->callAction('editBlockAction', data: ['code' => 'saved'], arguments: ['block' => $block->id, 'type' => 'code', 'isRoot' => true])->assertHasNoErrors();
            $this->assertTrue($attempted, 'The competing write must happen after the locked read.');
            $this->assertTrue($blocked, 'The row lock must cover validation of the snapshot and persistence.');
            $this->assertSame('saved', $block->fresh()->data['code']);
            $this->assertSame(1, $competitor->table('tgs_blocks')->where('id', $block->id)->update(['data' => json_encode(['code' => 'after commit'])]));
            $this->assertSame('after commit', $block->fresh()->data['code']);
        } finally {
            DB::purge('r123_competitor');
        }
    }
}
