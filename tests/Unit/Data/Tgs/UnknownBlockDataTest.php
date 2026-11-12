<?php

use ApiMedia\Tgs\Data\UnknownBlockData;

it('carries type and the legacy-transformed data blob untouched', function () {
    $b = UnknownBlockData::fromLegacy('gallery', ['id' => 'g1', 'images' => [['url' => 'x']]]);
    expect($b->type)->toBe('gallery')
        ->and($b->data)->toBe(['id' => 'g1', 'images' => [['url' => 'x']]]);
});

it('strips the backend-only @class dispatch key from the stored data', function () {
    $b = UnknownBlockData::fromLegacy('picture', ['@class' => 'X', 'id' => 'p1', 'caption' => 'c']);

    expect($b->type)->toBe('picture')
        ->and($b->data)->toHaveKeys(['id', 'caption'])
        ->and($b->data)->not->toHaveKey('@class');
});
