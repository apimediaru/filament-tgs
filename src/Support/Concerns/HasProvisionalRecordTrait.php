<?php

namespace ApiMedia\Tgs\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;

use function array_diff;
use function array_keys;
use function now;
use function session;

trait HasProvisionalRecordTrait
{
    use Prunable;

    public static function bootHasProvisionalRecordTrait(): void
    {
        // Провизорная запись — служебный черновик, а не контент: она не должна попадать
        // ни в публичную выдачу, ни в админские листинги. Колонка квалифицируется именем
        // таблицы, чтобы скоуп не ломался в запросах с join'ами.
        static::addGlobalScope('withoutProvisional', function (Builder $query): void {
            $query->whereNull($query->getModel()->getTable().'.provisional_at');
        });

        static::saving(function (self $model): void {
            if ($model->isProvisional() && $model->hasRealUserInput()) {
                $model->promote();
            }
        });
    }

    public function initializeHasProvisionalRecordTrait(): void
    {
        $this->mergeCasts([
            'provisional_at' => 'datetime',
        ]);
    }

    public function isProvisional(): bool
    {
        return $this->provisional_at !== null;
    }

    public function promote(): void
    {
        $this->provisional_at = null;

    }

    protected function hasRealUserInput(): bool
    {
        $ignoredKeys = [$this->getKeyName(), 'created_at', 'updated_at', 'provisional_at'];

        return array_diff(array_keys($this->getDirty()), $ignoredKeys) !== [];
    }

    public static function findProvisional(?string $token = null): ?static
    {
        // Resolved only via session() — a URL token by itself must never act as a capability/lookup key.
        // This prevents a guessed/shared token from opening another session's draft.
        $existingId = session(static::provisionalSessionKey($token));

        if ($existingId === null) {
            return null;
        }

        $existing = static::query()
            ->withoutGlobalScope('withoutProvisional')
            ->find($existingId);

        return $existing instanceof static && $existing->isProvisional() ? $existing : null;
    }

    public static function provisional(?string $token = null): static
    {
        $existing = static::findProvisional($token);

        if ($existing !== null) {
            return $existing;
        }

        $record = new static;
        $record->provisional_at = now();

        $record->save();

        session([static::provisionalSessionKey($token) => $record->getKey()]);

        return $record;
    }

    protected static function provisionalSessionKey(?string $token = null): string
    {
        $base = 'provisional_record:'.static::class;

        return $token !== null && $token !== '' ? $base.':'.$token : $base;
    }

    public function prunable(): Builder
    {
        return static::query()
            ->withoutGlobalScope('withoutProvisional')
            ->whereNotNull('provisional_at')
            ->where('provisional_at', '<', now()->subDay());
    }
}
