<?php

namespace ApiMedia\Tgs\Models;

use ApiMedia\Tgs\Support\TgsModels;
use ExileOfAranei\ListOrdering\Concerns\HasOrdering;
use ExileOfAranei\ListOrdering\Contracts\Orderable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $container_type
 * @property string $container_id
 * @property string $slot
 * @property string $rank
 * @property string $block_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Block $block
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockPlacement newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockPlacement newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockPlacement query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockPlacement whereBlockId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockPlacement whereContainerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockPlacement whereContainerType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockPlacement whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockPlacement whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockPlacement whereRank($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockPlacement whereSlot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockPlacement whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class BlockPlacement extends Model implements Orderable
{
    protected $table = 'tgs_block_placements';

    use HasOrdering;

    protected $fillable = ['container_type', 'container_id', 'slot', 'block_id'];

    /** @return BelongsTo<Block, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(TgsModels::block());
    }

    /** @return list<string> */
    public function orderingGroupColumns(): array
    {
        return ['container_type', 'container_id', 'slot'];
    }
}
