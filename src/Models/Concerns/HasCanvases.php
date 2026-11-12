<?php

namespace ApiMedia\Tgs\Models\Concerns;

use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

use function in_array;

/**
 * Хост объявляет свои ТГС-группы (несколько независимых деревьев блоков) статически
 * (ADR-019, заменяет HasTgsGroups — Этап 1 «канвасы как сущность»): группа = enum-ключ =
 * корневой слот привязки в block_placements. На created авто-создаётся Canvas (kind='host')
 * на каждый объявленный ключ; на deleting канвасы удаляются. Лёгкий flavor:
 * `\ApiMedia\Tgs\Support\TgsModels::canvas()::hostDescriptor()` резолвит существующий (host, slot)-rooting — rooting не меняется.
 */
trait HasCanvases
{
    /** @return list<string> ключи групп (обычно из enum) */
    abstract public static function canvasGroups(): array;

    public static function bootHasCanvases(): void
    {
        static::created(function (self $host): void {
            foreach ($host::canvasGroups() as $key) {
                $host->canvasRecords()->firstOrCreate(['group' => $key, 'kind' => 'host']);
            }
        });

        static::deleting(function (self $host): void {
            // Поштучно, а не ->delete() по билдеру: mass-delete не поднимает модельные
            // события, и `deleting`-каскад Canvas (backlog #25) не сработал бы. Оборачиваем
            // цикл в транзакцию: у хоста может быть несколько групп (Article: content +
            // bottom_content), и без атомарности сбой на середине цикла осиротил бы контент
            // ещё не удалённых групп.
            DB::transaction(function () use ($host): void {
                $host->canvasRecords()->get()->each->delete();
            });
        });
    }

    /** @return MorphMany<Canvas, $this> */
    public function canvasRecords(): MorphMany
    {
        return $this->morphMany(TgsModels::canvas(), 'host');
    }

    /** Ленивый get-or-create записи канваса по объявленному ключу. */
    public function canvas(string $key): Canvas
    {
        if (! in_array($key, static::canvasGroups(), true)) {
            throw new InvalidArgumentException("Unknown canvas group [{$key}] for ".static::class);
        }

        return $this->canvasRecords()->firstOrCreate(['group' => $key, 'kind' => 'host']);
    }

    /** Прямой дескриптор существующего rooting'а (без записи в БД) — сохраняется как есть. */
    public function canvasDescriptor(string $group): HostDescriptor
    {
        if (! in_array($group, static::canvasGroups(), true)) {
            throw new InvalidArgumentException("Unknown canvas group [{$group}] for ".static::class);
        }

        return HostDescriptor::forModelSlot($this, $group);
    }
}
