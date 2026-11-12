<?php

use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

pest()->extend(TestCase::class)->in('Feature', 'Unit/Data/Tgs', 'Unit/Tgs');

function fakeModel(): Model
{
    return new class extends Model {};
}
