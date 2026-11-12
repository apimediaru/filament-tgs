<?php

use ApiMedia\Tgs\Blocks\Accordion;
use ApiMedia\Tgs\Blocks\Content;
use ApiMedia\Tgs\Data\Blocks\AccordionData;
use ApiMedia\Tgs\Data\Blocks\ContentData;
use ApiMedia\Tgs\Data\Shared\AccordionItemData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Data\UnknownBlockData;
use ApiMedia\Tgs\Enums\ContentBlockType;

it('builds AccordionData, resolving each item and its nested content recursively', function () {
    $raw = [
        '@class' => Accordion::class,
        'id' => 'ac1',
        'section_heading' => 'Секция',
        'is_accordion' => true,
        'items' => [
            [
                'title' => 'Первый',
                'is_open' => true,
                'content' => [
                    ['type' => 'content', 'data' => ['@class' => Content::class, 'id' => 'c1', 'content' => '<p>a</p>']],
                    ['type' => 'legacy_widget', 'data' => ['@class' => 'ApiMedia\\Tgs\\Blocks\\UnmigratedWidget', 'id' => 'g1']],
                ],
            ],
            ['title' => 'Второй', 'content' => []],
        ],
    ];

    $data = AccordionData::fromStored($raw, fakeModel());

    expect($data->type)->toBe(ContentBlockType::Accordion)
        ->and($data->toArray()['type'])->toBe('accordion')
        ->and($data->id)->toBe('ac1')
        ->and($data->section)->toBeInstanceOf(TgsSectionData::class)
        ->and($data->is_accordion)->toBeTrue()
        ->and($data->items)->toHaveCount(2)
        ->and($data->items[0])->toBeInstanceOf(AccordionItemData::class)
        ->and($data->items[0]->title)->toBe('Первый')
        ->and($data->items[0]->is_open)->toBeTrue()
        ->and($data->items[0]->content)->toHaveCount(2)
        ->and($data->items[0]->content[0])->toBeInstanceOf(ContentData::class)
        ->and($data->items[0]->content[0]->content)->toBe('<p>a</p>')
        ->and($data->items[0]->content[1])->toBeInstanceOf(UnknownBlockData::class)
        ->and($data->items[0]->content[1]->data)->not->toHaveKey('@class')
        ->and($data->items[1]->title)->toBe('Второй')
        ->and($data->items[1]->is_open)->toBeNull()
        ->and($data->items[1]->content)->toBe([])
        ->and($data->toArray())->not->toHaveKey('@class');
});

it('treats absent items as an empty collection', function () {
    expect(AccordionData::fromStored(['id' => 'ac2'], fakeModel())->items)->toHaveCount(0);
});

it('throws on present-but-non-array items (degrade → UnknownBlockData upstream)', function () {
    AccordionData::fromStored(['id' => 'ac3', 'items' => 'x'], fakeModel());
})->throws(TypeError::class);

it('throws on a non-array item entry (degrade → UnknownBlockData upstream)', function () {
    AccordionData::fromStored(['id' => 'ac4', 'items' => ['not-an-array']], fakeModel());
})->throws(TypeError::class);

it('forbids Data::from()', function () {
    AccordionData::from(['id' => 'ac1']);
})->throws(LogicException::class);
