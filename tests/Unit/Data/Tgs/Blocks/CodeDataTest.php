<?php

use ApiMedia\Tgs\Blocks\Code;
use ApiMedia\Tgs\Data\Blocks\CodeData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;

it('builds CodeData from a stored block, nesting section and dropping sensitive keys', function () {
    $raw = [
        '@class' => Code::class, // sensitive — must not surface
        'id' => 'k1',
        'code' => '<div>x</div>',
        'is_pure' => true,
        'section_heading' => 'Заголовок',
    ];

    $data = CodeData::fromStored($raw, fakeModel());

    expect($data->type)->toBe(ContentBlockType::Code)
        ->and($data->toArray()['type'])->toBe('code')
        ->and($data->id)->toBe('k1')
        ->and($data->code)->toBe('<div>x</div>')
        ->and($data->is_pure)->toBeTrue()
        ->and($data->section)->toBeInstanceOf(TgsSectionData::class)
        ->and($data->section->section_heading)->toBe('Заголовок')
        ->and($data->toArray())->not->toHaveKey('@class');
});

it('tolerates a legacy block with no code/is_pure/section keys (fixed shape → null)', function () {
    $data = CodeData::fromStored(['id' => 'k2'], fakeModel());

    expect($data->id)->toBe('k2')
        ->and($data->code)->toBeNull()
        ->and($data->is_pure)->toBeNull();
});

it('forbids Data::from()', function () {
    CodeData::from(['id' => 'k1']);
})->throws(LogicException::class);
