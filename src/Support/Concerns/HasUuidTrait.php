<?php

namespace ApiMedia\Tgs\Support\Concerns;

use ApiMedia\Tgs\Support\Contracts\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasUuidTrait
{
    public static function bootHasUuidTrait()
    {
        static::creating(function (Model&HasUuid $model) {
            if (empty($model->getUuid())) {
                $model->{$model::getUuidKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function getUuid(): ?string
    {
        return $this->getAttribute(static::getUuidKeyName());
    }

    public static function findByUuid(string $uuid): ?static
    {
        $record = static::query()->where(static::getUuidKeyName(), $uuid)->first();

        return $record instanceof static ? $record : null;
    }

    public static function getUuidKeyName(): string
    {
        return 'uuid';
    }
}
