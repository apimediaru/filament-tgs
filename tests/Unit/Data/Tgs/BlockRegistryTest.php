<?php

use ApiMedia\Tgs\Data\BlockRegistry;
use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Enums\ContentBlockType;

it('resolves unknown types to null', function () {
    expect(BlockRegistry::resolve('does-not-exist'))->toBeNull();
});

it('every registered class implements ResolvesFromStored', function () {
    $map = BlockRegistry::map();
    expect($map)->toBeArray();

    foreach ($map as $type => $class) {
        expect(is_subclass_of($class, ResolvesFromStored::class))
            ->toBeTrue("{$type} => {$class} must implement ResolvesFromStored");
    }
});

it('keys the registry with valid ContentBlockType values only', function () {
    foreach (array_keys(BlockRegistry::map()) as $key) {
        expect(ContentBlockType::tryFrom($key))
            ->not->toBeNull("registry key '{$key}' is not a ContentBlockType");
    }
});
