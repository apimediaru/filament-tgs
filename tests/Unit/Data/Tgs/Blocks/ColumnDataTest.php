<?php

use ApiMedia\Tgs\Blocks\Column;
use ApiMedia\Tgs\Blocks\Content;
use ApiMedia\Tgs\Data\Blocks\ColumnData;
use ApiMedia\Tgs\Data\Blocks\ContentData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Data\UnknownBlockData;
use ApiMedia\Tgs\Enums\ContentBlockType;

it('builds ColumnData from a stored block, nesting section and resolving children recursively', function () {
    $raw = [
        '@class' => Column::class, // sensitive — must not surface
        'id' => 'col1',
        'section_heading' => 'Колонка',
        'content' => [
            // a migrated child → resolves to a typed ContentData
            ['type' => 'content', 'data' => [
                '@class' => Content::class,
                'id' => 'ch1',
                'content' => '<p>child</p>',
            ]],
            // an unmigrated child → degrades to UnknownBlockData (top-level @class stripped).
            // Uses a synthetic, never-registered type so this stays stable as real blocks migrate.
            ['type' => 'legacy_widget', 'data' => [
                '@class' => 'ApiMedia\\Tgs\\Blocks\\UnmigratedWidget',
                'id' => 'g1',
            ]],
        ],
    ];

    $data = ColumnData::fromStored($raw, fakeModel());

    expect($data->type)->toBe(ContentBlockType::Column)
        ->and($data->toArray()['type'])->toBe('column')
        ->and($data->id)->toBe('col1')
        ->and($data->section)->toBeInstanceOf(TgsSectionData::class)
        ->and($data->section->section_heading)->toBe('Колонка')
        ->and($data->content)->toBeArray()
        ->and($data->content)->toHaveCount(2)
        ->and($data->content[0])->toBeInstanceOf(ContentData::class)
        ->and($data->content[0]->content)->toBe('<p>child</p>')
        ->and($data->content[1])->toBeInstanceOf(UnknownBlockData::class)
        ->and($data->content[1]->type)->toBe('legacy_widget')
        ->and($data->content[1]->data)->not->toHaveKey('@class') // recursion strips nested @class
        ->and($data->toArray())->not->toHaveKey('@class');
});

it('treats absent or null content as an empty list (fixed shape)', function () {
    expect(ColumnData::fromStored(['id' => 'c2'], fakeModel())->content)->toBe([]);
    expect(ColumnData::fromStored(['id' => 'c3', 'content' => null], fakeModel())->content)->toBe([]);
});

it('throws on present-but-non-array content (degrade contract → UnknownBlockData upstream)', function () {
    ColumnData::fromStored(['id' => 'c4', 'content' => 'not-an-array'], fakeModel());
})->throws(TypeError::class);

it('forbids Data::from()', function () {
    ColumnData::from(['id' => 'c1']);
})->throws(LogicException::class);
