<?php

use ApiMedia\Tgs\Data\Blocks\PictureData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// fromStored теперь владеет сырым uuid→media трансформом (Final cleanup). Без uuid модель
// не трогается вовсе — bare fakeModel() достаточно. Сама резолюция uuid→конверсии покрыта
// tests/Feature/Tgs/PictureRawResolutionTest (нужны реальные media).
it('yields null image and null scalars for a raw block without uuid', function () {
    $data = PictureData::fromStored(['id' => 'p1', '@class' => 'X', 'alt' => 'A'], fakeModel());

    expect($data->id)->toBe('p1')
        ->and($data->image)->toBeNull()
        ->and($data->alt)->toBe('A')
        ->and($data->is_lightbox)->toBeNull()
        ->and($data->toArray())->not->toHaveKey('@class')
        ->and($data->toArray())->not->toHaveKey('uuid');
});

it('yields null image for a model that cannot host media (non-HasMedia)', function () {
    $data = PictureData::fromStored(['id' => 'p2', 'uuid' => 'u1'], fakeModel());

    expect($data->image)->toBeNull();
});

it('forbids Data::from()', function () {
    PictureData::from(['id' => 'p1']);
})->throws(LogicException::class);
