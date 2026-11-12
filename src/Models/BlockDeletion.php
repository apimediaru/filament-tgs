<?php

namespace ApiMedia\Tgs\Models;

use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only журнал мягкого удаления ТГС-блоков (Этап 4, «корзина и восстановление»). Одна
 * строка на операцию удаления — корень удалённого поддерева, независимо от его размера;
 * `payload` хранит позиционный якорь ({parent, slot, prev_sibling, next_sibling}) для
 * последующего восстановления. Записи неизменяемы — только `created_at`, без `updated_at`.
 *
 * @property string $id
 * @property int $canvas_id
 * @property string $block_id
 * @property string $transaction_id
 * @property array<array-key, mixed> $payload
 * @property int|null $actor_id
 * @property Carbon $created_at
 * @property-read Model|null $actor
 * @property-read Block $block
 * @property-read Canvas $canvas
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockDeletion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockDeletion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockDeletion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockDeletion whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockDeletion whereBlockId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockDeletion whereCanvasId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockDeletion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockDeletion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockDeletion wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BlockDeletion whereTransactionId($value)
 *
 * @mixin \Eloquent
 */
class BlockDeletion extends Model
{
    protected $table = 'tgs_block_deletions';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'canvas_id', 'block_id', 'transaction_id', 'payload', 'actor_id', 'created_at'];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];

    /** @return BelongsTo<Canvas, $this> */
    public function canvas(): BelongsTo
    {
        return $this->belongsTo(TgsModels::canvas());
    }

    /** @return BelongsTo<Block, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(TgsModels::block());
    }

    /** @return BelongsTo<Model, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(TgsModels::author(), 'actor_id');
    }
}
