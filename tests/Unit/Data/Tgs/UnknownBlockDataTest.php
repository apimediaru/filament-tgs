<?php

use ApiMedia\Tgs\Data\UnknownBlockData;

it('carries type and the legacy-transformed data blob untouched', function () {
    $b = UnknownBlockData::fromLegacy('gallery', ['id' => 'g1', 'images' => [['url' => 'x']]]);
    expect($b->type)->toBe('gallery')
        ->and($b->data)->toBe(['id' => 'g1', 'images' => [['url' => 'x']]]);
});

it('preserves lists and objects in the public JSON', function (array $data, string $json) {
    expect(UnknownBlockData::fromLegacy('legacy', $data)->toJson())->toBe($json);
})->with([
    'empty list' => [[], '{"type":"legacy","data":[]}'],
    'non-empty list' => [['intro'], '{"type":"legacy","data":["intro"]}'],
    'legacy object' => [['@class' => 'Legacy', 'title' => 'intro'], '{"type":"legacy","data":{"title":"intro"}}'],
]);

it('strips the backend-only @class dispatch key from the stored data', function () {
    $b = UnknownBlockData::fromLegacy('picture', ['@class' => 'X', 'id' => 'p1', 'caption' => 'c']);

    expect($b->type)->toBe('picture')
        ->and($b->data)->toHaveKeys(['id', 'caption'])
        ->and($b->data)->not->toHaveKey('@class');
});
