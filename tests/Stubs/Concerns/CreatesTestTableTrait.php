<?php

namespace ApiMedia\Tgs\Tests\Stubs\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesTestTableTrait
{
    public static function createTable(): void
    {
        $tableName = static::getTableName();

        if (Schema::hasTable($tableName)) {
            Schema::drop($tableName);
        }

        Schema::create($tableName, function (Blueprint $table) {
            static::describeTable($table);
        });
    }

    public static function dropTable(): void
    {
        Schema::dropIfExists(static::getTableName());
    }

    public static function getTableName(): string
    {
        return (new static)->getTable();
    }
}
