<?php

namespace ApiMedia\Tgs\Models;

use ApiMedia\Tgs\Database\Factories\CollectionFactory;
use ApiMedia\Tgs\Filament\Resources\CollectionResource;
use ApiMedia\Tgs\Models\Concerns\HasCanvases;
use ApiMedia\Tgs\Models\Contracts\HasCanvases as HasCanvasesContract;
use ApiMedia\Tgs\Support\Concerns\HasProvisionalRecordTrait;
use ApiMedia\Tgs\Support\Concerns\HasTgsHostLabel;
use ApiMedia\Tgs\Support\Contracts\HasProvisionalRecord;
use ApiMedia\Tgs\Support\Contracts\TgsHost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Именованный набор ТГС-блоков ("коллекция"): хост одной ТГС-группы `blocks`
 * (ADR-019). Не публичная сущность — без HasSlug/HasStatus.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $description
 * @property Carbon|null $provisional_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EloquentCollection<int, Canvas> $canvasRecords
 * @property-read int|null $canvas_records_count
 *
 * @method static \ApiMedia\Tgs\Database\Factories\CollectionFactory factory($count = null, $state = [])
 * @method static Builder<static>|Collection newModelQuery()
 * @method static Builder<static>|Collection newQuery()
 * @method static Builder<static>|Collection query()
 * @method static Builder<static>|Collection whereCreatedAt($value)
 * @method static Builder<static>|Collection whereDescription($value)
 * @method static Builder<static>|Collection whereId($value)
 * @method static Builder<static>|Collection whereName($value)
 * @method static Builder<static>|Collection whereProvisionalAt($value)
 * @method static Builder<static>|Collection whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Collection extends Model implements HasCanvasesContract, HasProvisionalRecord, TgsHost
{
    protected static function newFactory(): CollectionFactory
    {
        return CollectionFactory::new();
    }

    protected $table = 'tgs_collections';

    use HasCanvases;

    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    use HasProvisionalRecordTrait;
    use HasTgsHostLabel;

    protected $fillable = [
        'name',
        'description',
    ];

    public static function tgsHostKey(): string
    {
        return 'collection';
    }

    public function tgsEditUrl(): ?string
    {
        return CollectionResource::getUrl('edit', ['record' => $this]);
    }

    /** @return list<string> */
    public static function canvasGroups(): array
    {
        return ['blocks'];
    }
}
