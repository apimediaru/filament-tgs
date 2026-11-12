<?php

use ApiMedia\Tgs\Data\Blocks\GalleryData;

// Резолюция uuid→media[] покрыта tests/Feature/Tgs/GalleryRawResolutionTest.
it('yields empty images and null flags for a raw block without uuid', function () {
    $data = GalleryData::fromStored(['id' => 'g1', '@class' => 'X'], fakeModel());

    expect($data->images)->toHaveCount(0)
        ->and($data->aspect_ratio)->toBeNull()
        ->and($data->slide_count)->toBeNull()
        ->and($data->toArray())->not->toHaveKey('@class')
        ->and($data->toArray())->not->toHaveKey('uuid');
});

it('keeps non-numeric slide_count as-is and casts numeric strings to int', function () {
    expect(GalleryData::fromStored(['id' => 'g2', 'slide_count' => 'auto'], fakeModel())->slide_count)->toBe('auto')
        ->and(GalleryData::fromStored(['id' => 'g3', 'slide_count' => '2'], fakeModel())->slide_count)->toBe(2);
});

it('forbids Data::from()', function () {
    GalleryData::from(['id' => 'g1']);
})->throws(LogicException::class);
