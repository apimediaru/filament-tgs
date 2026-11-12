<?php

declare(strict_types=1);

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\BlockTreeAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves a leaf block with no children', function () {
    Block::factory()->picture(['uuid' => 'm1', 'alt' => 'A'])->withId('01J000000000000000000LEAF1')->create([
        'owner_container_type' => 'news',
        'owner_container_id' => '1',
    ]);

    $result = app(BlockTreeAssembler::class)->assembleBlock('01J000000000000000000LEAF1');

    expect($result)->toEqual([
        'type' => 'picture',
        'data' => ['uuid' => 'm1', 'alt' => 'A', 'id' => '01J000000000000000000LEAF1'],
    ]);
});

it('resolves a composite block together with its children', function () {
    Block::factory()->column()->withId('01J000000000000000000COL01')->create([
        'owner_container_type' => 'news',
        'owner_container_id' => '1',
    ]);
    // Left as a manual create(): content()'s default 'content' data key would leak into the
    // assembled output alongside 'body', breaking this test's exact-shape toEqual() assertion.
    Block::create([
        'id' => '01J000000000000000000CHI01',
        'type' => 'content',
        'data' => ['body' => 'hi'],
        'owner_container_type' => 'news',
        'owner_container_id' => '1',
    ]);
    BlockPlacement::forceCreate([
        'container_type' => 'block', 'container_id' => '01J000000000000000000COL01',
        'slot' => 'content', 'rank' => '000000',
        'block_id' => '01J000000000000000000CHI01',
    ]);

    $result = app(BlockTreeAssembler::class)->assembleBlock('01J000000000000000000COL01');

    expect($result)->toEqual([
        'type' => 'column',
        'data' => [
            'content' => [
                ['type' => 'content', 'data' => ['body' => 'hi', 'id' => '01J000000000000000000CHI01']],
            ],
            'id' => '01J000000000000000000COL01',
        ],
    ]);
});

it('returns null for a non-existent block id', function () {
    expect(app(BlockTreeAssembler::class)->assembleBlock('does-not-exist'))->toBeNull();
});

it('returns null for a soft-deleted block id, as if it did not exist', function () {
    Block::factory()->picture(['uuid' => 'm1'])->withId('01J000000000000000000DEL01')->create([
        'owner_container_type' => 'news',
        'owner_container_id' => '1',
        'deleted_at' => now(),
        'deleted_op_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]);

    expect(app(BlockTreeAssembler::class)->assembleBlock('01J000000000000000000DEL01'))->toBeNull();
});
