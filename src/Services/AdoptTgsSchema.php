<?php

namespace ApiMedia\Tgs\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Explicit baseline for a verified, already-existing TGS schema. Never creates content tables. */
final class AdoptTgsSchema
{
    public const MIGRATION = '0001_01_01_000000_create_tgs_tables';

    public const COLUMNS = [
        'tgs_blocks' => ['id', 'type', 'data', 'name', 'owner_container_type', 'owner_container_id', 'committed_at', 'is_searchable', 'is_locked', 'lock_mode', 'deleted_at', 'deleted_op_id'],
        'tgs_block_placements' => ['id', 'container_type', 'container_id', 'slot', 'rank', 'block_id'],
        'tgs_block_media' => ['id', 'model_type', 'model_id', 'uuid', 'custom_properties', 'generated_conversions', 'file_name', 'disk', 'size'],
        'tgs_canvases' => ['id', 'uuid', 'kind', 'key', 'host_type', 'host_id', 'group', 'owner_key', 'cache_keys', 'claimed_at', 'released_at'],
        'tgs_block_versions' => ['id', 'block_id', 'data', 'created_by', 'created_at'],
        'tgs_block_deletions' => ['id', 'canvas_id', 'block_id', 'transaction_id', 'payload', 'actor_id', 'created_at'],
        'tgs_collections' => ['id', 'name', 'description', 'provisional_at'],
        'tgs_canvas_claim_locks' => ['owner_key'],
    ];

    public function run(): bool
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                throw new LogicException('Cannot adopt an incomplete TGS schema: '.$table);
            }
        }
        foreach (['tgs_block_placements' => 'block_id', 'tgs_block_media' => 'model_id', 'tgs_block_versions' => 'block_id', 'tgs_block_deletions' => 'block_id'] as $table => $column) {
            $matches = array_filter(Schema::getForeignKeys($table), fn (array $key): bool => $key['columns'] === [$column] && $key['foreign_table'] === 'tgs_blocks' && $key['on_delete'] === 'cascade');
            if ($matches === []) {
                throw new LogicException('Cannot adopt without a block cascade: '.$table);
            }
        }
        $repository = app('migration.repository');
        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        return DB::transaction(function () use ($repository): bool {
            if (in_array(self::MIGRATION, $repository->getRan(), true)) {
                return false;
            }
            $repository->log(self::MIGRATION, max(1, $repository->getLastBatchNumber()));

            return true;
        });
    }
}
