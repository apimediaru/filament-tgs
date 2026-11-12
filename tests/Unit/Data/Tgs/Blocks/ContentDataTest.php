<?php

use ApiMedia\Tgs\Blocks\Content;
use ApiMedia\Tgs\Data\Blocks\ContentData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;

it('builds ContentData from a stored block, nesting section and dropping sensitive keys', function () {
    $raw = [
        '@class' => Content::class, // sensitive — must not surface
        'id' => 'c1',
        'content' => '<p>Hi</p>',
        'section_heading' => 'Заголовок',
    ];

    $data = ContentData::fromStored($raw, fakeModel());

    expect($data->type)->toBe(ContentBlockType::Content)
        ->and($data->toArray()['type'])->toBe('content')
        ->and($data->id)->toBe('c1')
        ->and($data->content)->toBe('<p>Hi</p>')
        ->and($data->section)->toBeInstanceOf(TgsSectionData::class)
        ->and($data->section->section_heading)->toBe('Заголовок')
        ->and($data->toArray())->not->toHaveKey('@class');
});

it('tolerates a legacy block with no content/section keys', function () {
    $data = ContentData::fromStored(['id' => 'c2'], fakeModel());
    expect($data->id)->toBe('c2')
        ->and($data->content)->toBeNull();
});

it('forbids Data::from()', function () {
    ContentData::from(['id' => 'c1']);
})->throws(LogicException::class);
