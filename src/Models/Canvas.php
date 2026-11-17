<?php

namespace ApiMedia\Tgs\Models;

use ApiMedia\Tgs\Models\Concerns\DeletesWithCanvasContent;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Services\TgsCanvasClaims;
use ApiMedia\Tgs\Support\Concerns\HasUuidTrait;
use ApiMedia\Tgs\Support\Contracts\HasUuid;
use ApiMedia\Tgs\Support\Contracts\InvalidatesCache;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsModels;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * ТГС-канвас как сущность (Этап 1 «канвасы как сущность»): заменяет TgsGroup (host-канвасы),
 * TgsHostItem (embedded-канвасы) и canvas-роль Virtual (named-канвасы), одной таблицей с
 * дискриминатором `kind`:
 * - `host` — привязан к реальной Eloquent-модели (host_type/host_id) + произвольному `group`
 *   (было TgsGroup::groupable/key). Несколько host-канвасов на одном хосте — разные группы
 *   (Article: content + bottom_content).
 *
 * - `named` — не привязан ни к какой модели, идентифицируется `key` (ApiMedia\Tgs\Enums\TgsNamedCanvas
 *   value) — заменяет Virtual-хосты CANVAS_DEMO/BLOCK_LIBRARY.
 * - `embedded` — не привязан ни к модели, ни к key/group; чистая идентичность (сам `id`),
 *   владелец хранит только canvas_id (см. App\Filament\Clusters\Settings\Pages\ManageAbout FAQ).
 *
 * @property int $id
 * @property string|null $uuid
 * @property string|null $key
 * @property string|null $host_type
 * @property string|null $host_id
 * @property string|null $group
 * @property string|null $owner_key
 * @property list<string>|null $cache_keys
 * @property Carbon|null $claimed_at
 * @property Carbon|null $released_at
 * @property string $kind
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $host
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas whereGroup($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas whereHostId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas whereHostType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Canvas whereUuid($value)
 *
 * @mixin \Eloquent
 */
class Canvas extends Model implements HasUuid, InvalidatesCache
{
    protected $table = 'tgs_canvases';

    use DeletesWithCanvasContent;
    use HasUuidTrait;

    protected $fillable = [
        'key',
        'host_type',
        'host_id',
        'group',
        'kind',
    ];

    protected function casts(): array
    {
        return ['cache_keys' => 'array', 'claimed_at' => 'datetime', 'released_at' => 'datetime'];
    }

    /** @param list<int|string> $ids
     * @param  list<string>  $cacheKeys
     */
    public static function claim(array $ids, string $ownerKey, array $cacheKeys = []): void
    {
        app(TgsCanvasClaims::class)->sync($ids, $ownerKey, $cacheKeys);
    }

    /**
     * Scope-blind нарочно (как у TgsGroup::groupable() до него): host может быть провизорным
     * хостом ещё до сохранения пользователем, hostDescriptor()/deleting-каскад должны
     * резолвить его независимо от статуса.
     *
     * @return MorphTo<Model, $this>
     */
    public function host(): MorphTo
    {
        return $this->morphTo('host', 'host_type', 'host_id')->withoutGlobalScopes();
    }

    public function hostDescriptor(): HostDescriptor
    {
        return match ($this->kind) {
            'host' => HostDescriptor::forModelSlot($this->host, $this->group),
            'named', 'embedded' => HostDescriptor::forCanvas($this),
            default => throw new \LogicException("Unknown Canvas kind [{$this->kind}]"),
        };
    }

    /**
     * Ленивый get-or-create именованного канваса (canvas_demo/library), заменяет Virtual-хосты.
     * Принимает произвольный `BackedEnum` (не только `ApiMedia\Tgs\Enums\TgsNamedCanvas` — фича вправе
     * завести свой собственный енам для своих named-канвасов) или простую строку — не каждый
     * named-канвас достаточно значим, чтобы вообще заводить под него енам-кейс где-либо;
     * разработчик сам решает, когда нужна типобезопасность енама, а когда хватит строки.
     */
    public static function named(BackedEnum|string $case): self
    {
        return TgsModels::canvas()::query()->firstOrCreate(
            ['key' => $case instanceof BackedEnum ? (string) $case->value : $case],
            ['kind' => 'named'],
        );
    }

    /** A provisional embedded canvas; its owner must claim the saved list before grace expires. */
    public static function createEmbedded(): self
    {
        return TgsModels::canvas()::create(['kind' => 'embedded']);
    }

    /** Embedded cache keys belong to the persisted claim; named canvases use exact config keys. */
    public function cacheKeysToInvalidate(): array
    {
        if ($this->kind === 'named') {
            return config("tgs.named_canvas_cache_keys.{$this->key}", []);
        }

        if ($this->kind !== 'embedded') {
            return [];
        }

        return $this->owner_key !== null ? ($this->cache_keys ?? []) : [];
    }

    /**
     * Каскад очистки контента канваса (наследует backlog #25 у TgsGroup): удаление канваса
     * сносит его blocks/placements, а не только саму строку `canvases`.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $canvas): void {
            if ($canvas->kind === 'host' && ! $canvas->host()->exists()) {
                // Осиротевший host-канвас (полиморфная связь уже недоступна) — нечего каскадить.
                return;
            }

            $host = $canvas->hostDescriptor();

            app(CanvasPersistence::class)->purgeSlot(
                $host, $host->containerType, $host->containerId, $host->slot,
            );
        });
    }
}
