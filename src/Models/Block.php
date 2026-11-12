<?php

namespace ApiMedia\Tgs\Models;

use ApiMedia\Tgs\Database\Factories\BlockFactory;
use ApiMedia\Tgs\Enums\BlockLockMode;
use ApiMedia\Tgs\Models\Concerns\HasContentMediaUploads;
use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property string $id
 * @property string $type
 * @property array<array-key, mixed>|null $data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $owner_container_type
 * @property string $owner_container_id
 * @property bool $is_searchable
 * @property bool $is_locked
 * @property BlockLockMode $lock_mode
 * @property Carbon|null $committed_at
 * @property string|null $name
 * @property Carbon|null $deleted_at
 * @property string|null $deleted_op_id
 * @property-read MediaCollection<int, BlockMedia> $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BlockPlacement> $placements
 * @property-read int|null $placements_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BlockVersion> $versions
 * @property-read int|null $versions_count
 *
 * @method static Builder<static>|Block alive()
 * @method static Builder<static>|Block committed()
 * @method static \ApiMedia\Tgs\Database\Factories\BlockFactory factory($count = null, $state = [])
 * @method static Builder<static>|Block newModelQuery()
 * @method static Builder<static>|Block newQuery()
 * @method static Builder<static>|Block query()
 * @method static Builder<static>|Block searchable()
 * @method static Builder<static>|Block whereCommittedAt($value)
 * @method static Builder<static>|Block whereCreatedAt($value)
 * @method static Builder<static>|Block whereData($value)
 * @method static Builder<static>|Block whereDeletedAt($value)
 * @method static Builder<static>|Block whereDeletedOpId($value)
 * @method static Builder<static>|Block whereId($value)
 * @method static Builder<static>|Block whereIsLocked($value)
 * @method static Builder<static>|Block whereIsSearchable($value)
 * @method static Builder<static>|Block whereLockMode($value)
 * @method static Builder<static>|Block whereName($value)
 * @method static Builder<static>|Block whereOwnerContainerId($value)
 * @method static Builder<static>|Block whereOwnerContainerType($value)
 * @method static Builder<static>|Block whereType($value)
 * @method static Builder<static>|Block whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Block extends Model implements HasMedia
{
    protected static function newFactory(): BlockFactory
    {
        return BlockFactory::new();
    }

    protected $table = 'tgs_blocks';

    use HasContentMediaUploads;

    /** @use HasFactory<BlockFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use Prunable;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'type', 'data', 'name', 'owner_container_type', 'owner_container_id', 'is_searchable', 'is_locked', 'lock_mode', 'deleted_at', 'deleted_op_id'];

    protected $casts = ['data' => 'array', 'is_searchable' => 'boolean', 'is_locked' => 'boolean', 'lock_mode' => BlockLockMode::class, 'committed_at' => 'datetime', 'deleted_at' => 'datetime'];

    protected $attributes = [
        'is_locked' => false,
        'lock_mode' => 'cascade',
    ];

    public function getMediaModel(): string
    {
        return TgsModels::block_media();
    }

    /**
     * Files in the content-media collection whose `custom_properties.uuid` matches the given
     * uuid — the source of truth for "is an image really attached for this data-blob uuid"
     * (Picture/Gallery `blockPreview()`), since the upload field itself never dehydrates into
     * `data`.
     *
     * @return Collection<int, Media>
     */
    public function contentMediaWithUuid(?string $uuid): Collection
    {
        if ($uuid === null) {
            return collect();
        }

        return $this->getMedia(static::getContentMediaCollectionName())
            ->filter(fn (Media $media): bool => $media->getCustomProperty('uuid') === $uuid);
    }

    /**
     * @return array{owner_container_type: string, owner_container_id: string}
     */
    public static function unclaimedOwner(): array
    {
        return ['owner_container_type' => 'orphan', 'owner_container_id' => 'unknown'];
    }

    /** @return HasMany<BlockPlacement, $this> */
    public function placements(): HasMany
    {
        return $this->hasMany(TgsModels::placement(), 'block_id');
    }

    /** @return HasMany<BlockVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TgsModels::version(), 'block_id');
    }

    /**
     * Abandoned drafts: uncommitted (`committed_at IS NULL`) and older than a day — e.g. the
     * user closed the browser tab without ever calling the unmount/cancel path. Defense-in-depth:
     * additionally require the ABSENCE of a placement — even if `committed_at` somehow fails to
     * be stamped for a genuinely placed block (a future backfill/commit-path bug), the lack of a
     * `BlockPlacement` is a second, independent guarantee that a visible/placed block never gets
     * pruned.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNull('committed_at')
            ->where('created_at', '<', now()->subDay())
            ->whereDoesntHave('placements');
    }

    /** @param  Builder<static>  $query
     *  @return Builder<static> */
    public function scopeSearchable(Builder $query): Builder
    {
        return $query->where('is_searchable', true);
    }

    /**
     * Не в корзине (Этап 4: мягкое удаление — `deleted_at`, без глобального скоупа). Введён в
     * hotfix-срезе 1 (баг 8); остальные ручные `whereNull('deleted_at')` переводятся в срезе C4.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAlive(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Не черновик (ADR-020: `committed_at = NULL` — блок создан, но ни разу не размещён).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCommitted(Builder $query): Builder
    {
        return $query->whereNotNull('committed_at');
    }
}
