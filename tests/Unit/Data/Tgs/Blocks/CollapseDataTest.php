<?php

use ApiMedia\Tgs\Blocks\Collapse;
use ApiMedia\Tgs\Blocks\Content;
use ApiMedia\Tgs\Data\Blocks\CollapseData;
use ApiMedia\Tgs\Data\Blocks\ContentData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Data\UnknownBlockData;
use ApiMedia\Tgs\Enums\ContentBlockType;

it('builds CollapseData from a stored block, nesting section and resolving content recursively', function () {
    $raw = [
        '@class' => Collapse::class, // sensitive — must not surface
        'id' => 'cl1',
        'title' => '<p>Заголовок</p>',
        'is_open' => true,
        'section_heading' => 'Секция',
        'content' => [
            ['type' => 'content', 'data' => [
                '@class' => Content::class,
                'id' => 'ch1',
                'content' => '<p>child</p>',
            ]],
            // an unmigrated child → degrades to UnknownBlockData; synthetic never-registered type.
            ['type' => 'legacy_widget', 'data' => [
                '@class' => 'ApiMedia\\Tgs\\Blocks\\UnmigratedWidget',
                'id' => 'g1',
            ]],
        ],
    ];

    $data = CollapseData::fromStored($raw, fakeModel());

    expect($data->type)->toBe(ContentBlockType::Collapse)
        ->and($data->toArray()['type'])->toBe('collapse')
        ->and($data->id)->toBe('cl1')
        ->and($data->title)->toBe('<p>Заголовок</p>')
        ->and($data->is_open)->toBeTrue()
        ->and($data->section)->toBeInstanceOf(TgsSectionData::class)
        ->and($data->section->section_heading)->toBe('Секция')
        ->and($data->content)->toHaveCount(2)
        ->and($data->content[0])->toBeInstanceOf(ContentData::class)
        ->and($data->content[0]->content)->toBe('<p>child</p>')
        ->and($data->content[1])->toBeInstanceOf(UnknownBlockData::class)
        ->and($data->content[1]->data)->not->toHaveKey('@class')
        ->and($data->toArray())->not->toHaveKey('@class');
});

it('treats absent or null content as an empty list', function () {
    expect(CollapseData::fromStored(['id' => 'c2'], fakeModel())->content)->toBe([]);
    expect(CollapseData::fromStored(['id' => 'c3', 'content' => null], fakeModel())->content)->toBe([]);
});

it('throws on present-but-non-array content (degrade → UnknownBlockData upstream)', function () {
    CollapseData::fromStored(['id' => 'c4', 'content' => 'x'], fakeModel());
})->throws(TypeError::class);

it('forbids Data::from()', function () {
    CollapseData::from(['id' => 'c1']);
})->throws(LogicException::class);
