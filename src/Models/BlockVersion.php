<?php

namespace ApiMedia\Tgs\Models;

use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $block_id
 * @property array<array-key, mixed> $data
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Block $block
 * @property-read Model|null $createdBy
 */
class BlockVersion extends Model
{
    protected $table = 'tgs_block_versions';

    public $timestamps = false;

    protected $fillable = ['block_id', 'data', 'created_by', 'created_at'];

    protected $casts = ['data' => 'array', 'created_at' => 'datetime'];

    /** @return BelongsTo<Block, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(TgsModels::block());
    }

    /** @return BelongsTo<Model, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TgsModels::author(), 'created_by');
    }

    /**
     * Task 5 fix: `created_at` is a `timestamp` column at second precision (Laravel's
     * `timestamp()` default), so two versions created within the same second (e.g. two edits
     * in quick succession within one request) tie on it — ORDER BY created_at DESC alone then
     * falls back to non-deterministic physical row order. `id DESC` (monotonically increasing
     * PK) is a stable, always-correct secondary tiebreaker for "latest first".
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
