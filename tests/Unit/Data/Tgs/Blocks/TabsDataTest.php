<?php

use ApiMedia\Tgs\Blocks\Content;
use ApiMedia\Tgs\Blocks\Tabs;
use ApiMedia\Tgs\Data\Blocks\ContentData;
use ApiMedia\Tgs\Data\Blocks\TabsData;
use ApiMedia\Tgs\Data\Shared\TabItemData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;

it('builds TabsData, resolving each item and its nested content recursively', function () {
    $raw = [
        '@class' => Tabs::class,
        'id' => 'tb1',
        'section_heading' => 'Секция',
        'items' => [
            ['title' => 'Вкладка 1', 'content' => [
                ['type' => 'content', 'data' => ['@class' => Content::class, 'id' => 'c1', 'content' => '<p>tab</p>']],
            ]],
        ],
    ];

    $data = TabsData::fromStored($raw, fakeModel());

    expect($data->type)->toBe(ContentBlockType::Tabs)
        ->and($data->toArray()['type'])->toBe('tabs')
        ->and($data->id)->toBe('tb1')
        ->and($data->section)->toBeInstanceOf(TgsSectionData::class)
        ->and($data->items)->toHaveCount(1)
        ->and($data->items[0])->toBeInstanceOf(TabItemData::class)
        ->and($data->items[0]->title)->toBe('Вкладка 1')
        ->and($data->items[0]->content)->toHaveCount(1)
        ->and($data->items[0]->content[0])->toBeInstanceOf(ContentData::class)
        ->and($data->items[0]->content[0]->content)->toBe('<p>tab</p>')
        ->and($data->toArray())->not->toHaveKey('@class');
});

it('treats absent items as an empty collection', function () {
    expect(TabsData::fromStored(['id' => 'tb2'], fakeModel())->items)->toHaveCount(0);
});

it('throws on present-but-non-array items (degrade → UnknownBlockData upstream)', function () {
    TabsData::fromStored(['id' => 'tb3', 'items' => 'x'], fakeModel());
})->throws(TypeError::class);

it('forbids Data::from()', function () {
    TabsData::from(['id' => 'tb1']);
})->throws(LogicException::class);
