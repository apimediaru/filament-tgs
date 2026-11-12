<?php

use ApiMedia\Tgs\Tests\Support\Tgs\FakeBlockData;

it('returns null for an absent (or stored-null) key and the value for a present key', function () {
    expect(FakeBlockData::build(['id' => 'x'])->title)->toBeNull();
    expect(FakeBlockData::build(['id' => 'x', 'title' => null])->title)->toBeNull();
    expect(FakeBlockData::build(['id' => 'x', 'title' => 'Hi'])->title)->toBe('Hi');
});

it('generates a fallback id when stored block lacks one', function () {
    expect(FakeBlockData::build([])->id)->not->toBe('');
});

it('forbids Data::from() to prevent bypassing fromStored transforms', function () {
    FakeBlockData::from(['id' => 'x', 'title' => 'Hi']);
})->throws(LogicException::class);
