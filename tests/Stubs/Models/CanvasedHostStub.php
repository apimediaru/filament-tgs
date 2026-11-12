<?php

namespace ApiMedia\Tgs\Tests\Stubs\Models;

use ApiMedia\Tgs\Models\Concerns\HasCanvases;
use ApiMedia\Tgs\Tests\Stubs\Concerns\CreatesTestTableTrait;
use ApiMedia\Tgs\Tests\Stubs\Contracts\CreatesTestTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

class CanvasedHostStub extends Model implements CreatesTestTable
{
    use CreatesTestTableTrait;
    use HasCanvases;

    protected $table = 'canvased_host_stubs';

    public $timestamps = false;

    protected $guarded = [];

    /** @return list<string> */
    public static function canvasGroups(): array
    {
        return ['description', 'room_1', 'room_2'];
    }

    public static function describeTable(Blueprint $table): void
    {
        $table->id();
    }
}
