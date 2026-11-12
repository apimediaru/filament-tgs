<?php

use ApiMedia\Tgs\Blocks\Content;
use ApiMedia\Tgs\Blocks\Popup;
use ApiMedia\Tgs\Data\Blocks\ContentData;
use ApiMedia\Tgs\Data\Blocks\PopupData;
use ApiMedia\Tgs\Data\Shared\TgsButtonData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;

it('builds PopupData, nesting section + button and resolving content recursively', function () {
    $raw = [
        '@class' => Popup::class,
        'id' => 'pp1',
        'section_heading' => 'Секция',
        'button_label' => 'Открыть',
        'button_theme' => 'primary',
        'button_variant' => 'solid',
        'button_alignment' => 'center',
        'button_class' => 'my-btn',
        'size' => 'lg',
        'content' => [
            ['type' => 'content', 'data' => ['@class' => Content::class, 'id' => 'c1', 'content' => '<p>inner</p>']],
        ],
    ];

    $data = PopupData::fromStored($raw, fakeModel());

    expect($data->type)->toBe(ContentBlockType::Popup)
        ->and($data->toArray()['type'])->toBe('popup')
        ->and($data->id)->toBe('pp1')
        ->and($data->section)->toBeInstanceOf(TgsSectionData::class)
        ->and($data->section->section_heading)->toBe('Секция')
        ->and($data->button)->toBeInstanceOf(TgsButtonData::class)
        ->and($data->button->button_label)->toBe('Открыть')
        ->and($data->button->button_theme)->toBe('primary')
        ->and($data->button->button_alignment)->toBe('center')
        ->and($data->size)->toBe('lg')
        ->and($data->content)->toHaveCount(1)
        ->and($data->content[0])->toBeInstanceOf(ContentData::class)
        ->and($data->content[0]->content)->toBe('<p>inner</p>')
        ->and($data->toArray())->not->toHaveKey('@class');
});

it('nulls absent button/size fields and empties absent content (fixed shape)', function () {
    $data = PopupData::fromStored(['id' => 'pp2'], fakeModel());
    expect($data->button)->toBeInstanceOf(TgsButtonData::class)
        ->and($data->button->button_label)->toBeNull()
        ->and($data->size)->toBeNull()
        ->and($data->content)->toBe([]);
});

it('throws on present-but-non-array content (degrade → UnknownBlockData upstream)', function () {
    PopupData::fromStored(['id' => 'pp3', 'content' => 'x'], fakeModel());
})->throws(TypeError::class);

it('forbids Data::from()', function () {
    PopupData::from(['id' => 'pp1']);
})->throws(LogicException::class);
