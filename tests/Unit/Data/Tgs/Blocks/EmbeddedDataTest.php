<?php

use ApiMedia\Tgs\Data\Blocks\EmbeddedData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Enums\ContentBlockType;

// fromStored теперь владеет сырым source→embedded_src трансформом (Final cleanup).
// Фикстура с iframe-HTML детерминирована: parseEmbeddedURL вырезает src-атрибут регэкспом,
// а MediaEmbed для неизвестного хоста возвращает вырезанный src как есть.
it('parses raw source into embedded_src', function () {
    $raw = [
        '@class' => 'X',
        'id' => 'em1',
        'source' => '<iframe src="https://player.example/x"></iframe>',
        'ratio' => '16:9',
        'section_heading' => 'Видео',
    ];

    $data = EmbeddedData::fromStored($raw, fakeModel());

    expect($data->type)->toBe(ContentBlockType::Embedded)
        ->and($data->embedded_src)->toBe('https://player.example/x')
        ->and($data->source)->toBe('<iframe src="https://player.example/x"></iframe>')
        ->and($data->ratio)->toBe('16:9')
        ->and($data->section)->toBeInstanceOf(TgsSectionData::class)
        ->and($data->section->section_heading)->toBe('Видео')
        ->and($data->toArray())->not->toHaveKey('@class')
        ->and($data->toArray())->not->toHaveKey('embedded_image');
});

it('yields null embedded_src for a block without source (fixed shape)', function () {
    $data = EmbeddedData::fromStored(['id' => 'em2'], fakeModel());

    expect($data->source)->toBeNull()
        ->and($data->embedded_src)->toBeNull()
        ->and($data->ratio)->toBeNull();
});

it('forbids Data::from()', function () {
    EmbeddedData::from(['id' => 'em1']);
})->throws(LogicException::class);
