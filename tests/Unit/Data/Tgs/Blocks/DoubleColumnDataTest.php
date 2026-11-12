<?php

use ApiMedia\Tgs\Blocks\Content;
use ApiMedia\Tgs\Blocks\DoubleColumn;
use ApiMedia\Tgs\Data\Blocks\ContentData;
use ApiMedia\Tgs\Data\Blocks\DoubleColumnData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;

it('builds DoubleColumnData, resolving left and right recursively and mapping scalars', function () {
    $raw = [
        '@class' => DoubleColumn::class,
        'id' => 'dc1',
        'section_heading' => 'Секция',
        'left_column_width' => '1/3',
        'right_column_width' => '2/3',
        'justification' => 'center',
        'is_aligned_vertically' => true,
        'is_swapped' => false,
        'left' => [
            ['type' => 'content', 'data' => ['@class' => Content::class, 'id' => 'l1', 'content' => '<p>left</p>']],
        ],
        'right' => [
            ['type' => 'content', 'data' => ['@class' => Content::class, 'id' => 'r1', 'content' => '<p>right</p>']],
        ],
    ];

    $data = DoubleColumnData::fromStored($raw, fakeModel());

    expect($data->type)->toBe(ContentBlockType::DoubleColumn)
        ->and($data->toArray()['type'])->toBe('double_column')
        ->and($data->id)->toBe('dc1')
        ->and($data->section)->toBeInstanceOf(TgsSectionData::class)
        ->and($data->left_column_width)->toBe('1/3')
        ->and($data->right_column_width)->toBe('2/3')
        ->and($data->justification)->toBe('center')
        ->and($data->is_aligned_vertically)->toBeTrue()
        ->and($data->is_swapped)->toBeFalse()
        ->and($data->left)->toHaveCount(1)
        ->and($data->left[0])->toBeInstanceOf(ContentData::class)
        ->and($data->left[0]->content)->toBe('<p>left</p>')
        ->and($data->right)->toHaveCount(1)
        ->and($data->right[0])->toBeInstanceOf(ContentData::class)
        ->and($data->right[0]->content)->toBe('<p>right</p>')
        ->and($data->toArray())->not->toHaveKey('@class');
});

it('treats absent left/right as empty lists', function () {
    $data = DoubleColumnData::fromStored(['id' => 'dc2'], fakeModel());
    expect($data->left)->toBe([])->and($data->right)->toBe([]);
});

it('throws on present-but-non-array left (degrade → UnknownBlockData upstream)', function () {
    DoubleColumnData::fromStored(['id' => 'dc3', 'left' => 'x'], fakeModel());
})->throws(TypeError::class);

it('forbids Data::from()', function () {
    DoubleColumnData::from(['id' => 'dc1']);
})->throws(LogicException::class);
