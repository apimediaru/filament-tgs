<?php

declare(strict_types=1);

use ApiMedia\Tgs\Support\BlockTreeHydrator;

it('rebuilds a flat root', function () {
    $blocks = [
        ['id' => 'PIC', 'type' => 'picture', 'data' => ['uuid' => 'm1']],
    ];
    $edges = [
        ['parent_id' => null, 'slot' => BlockTreeHydrator::ROOT_SLOT, 'position' => 0, 'child_id' => 'PIC'],
    ];

    expect(BlockTreeHydrator::hydrate($blocks, $edges))->toEqual([
        ['type' => 'picture', 'data' => ['uuid' => 'm1', 'id' => 'PIC']],
    ]);
});

it('rebuilds a direct child slot', function () {
    $tree = [[
        'type' => 'column',
        'data' => ['id' => 'COL', 'content' => [
            ['type' => 'content', 'data' => ['id' => 'CH', 'body' => 'hi']],
        ]],
    ]];

    $back = BlockTreeHydrator::hydrate([
        ['id' => 'COL', 'type' => 'column', 'data' => []],
        ['id' => 'CH', 'type' => 'content', 'data' => ['body' => 'hi']],
    ], [
        ['parent_id' => null, 'slot' => BlockTreeHydrator::ROOT_SLOT, 'position' => 0, 'child_id' => 'COL'],
        ['parent_id' => 'COL', 'slot' => 'content', 'position' => 0, 'child_id' => 'CH'],
    ]);

    expect($back)->toEqual($tree);
});

it('rebuilds dotted child slots and preserves item metadata', function () {
    $tree = [[
        'type' => 'accordion',
        'data' => ['id' => 'ACC', 'is_accordion' => true, 'items' => [
            ['title' => 'FAQ 1', 'is_open' => false, 'content' => [
                ['type' => 'content', 'data' => ['id' => 'A1', 'body' => 'one']],
            ]],
            ['title' => 'FAQ 2', 'is_open' => true, 'content' => [
                ['type' => 'picture', 'data' => ['id' => 'A2', 'uuid' => 'mm']],
            ]],
        ]],
    ]];

    $back = BlockTreeHydrator::hydrate([
        ['id' => 'ACC', 'type' => 'accordion', 'data' => ['is_accordion' => true, 'items' => [
            ['title' => 'FAQ 1', 'is_open' => false], ['title' => 'FAQ 2', 'is_open' => true],
        ]]],
        ['id' => 'A1', 'type' => 'content', 'data' => ['body' => 'one']],
        ['id' => 'A2', 'type' => 'picture', 'data' => ['uuid' => 'mm']],
    ], [
        ['parent_id' => null, 'slot' => BlockTreeHydrator::ROOT_SLOT, 'position' => 0, 'child_id' => 'ACC'],
        ['parent_id' => 'ACC', 'slot' => 'items.0.content', 'position' => 0, 'child_id' => 'A1'],
        ['parent_id' => 'ACC', 'slot' => 'items.1.content', 'position' => 0, 'child_id' => 'A2'],
    ]);

    expect($back)->toEqual($tree);
});
