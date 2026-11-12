<?php

namespace ApiMedia\Tgs\Tests\Stubs\Contracts;

use Illuminate\Database\Schema\Blueprint;

interface CreatesTestTable
{
    /**
     * Create the test table in the database
     */
    public static function createTable(): void;

    /**
     * Drop the test table from the database
     */
    public static function dropTable(): void;

    /**
     * Get the table name for this test model
     */
    public static function getTableName(): string;

    /**
     * Describe the test table schema
     */
    public static function describeTable(Blueprint $table): void;
}
