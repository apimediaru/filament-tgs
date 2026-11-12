<?php

use ApiMedia\Tgs\Data\Shared\TgsSectionData;

it('plucks known section keys and ignores block/sensitive keys', function () {
    $section = TgsSectionData::fromStored([
        'id' => 'b1', '@class' => 'X', 'content' => '...', // not section
        'section_heading' => 'Заголовок', 'section_bg' => '#fff', 'container_size' => 'lg',
    ]);

    expect($section->section_heading)->toBe('Заголовок')
        ->and($section->section_bg)->toBe('#fff')
        ->and($section->container_size)->toBe('lg');
});

it('represents absent section keys as null (fixed shape: every key always present)', function () {
    $section = TgsSectionData::fromStored(['id' => 'b1']);
    expect($section->section_heading)->toBeNull()
        ->and($section->container_position)->toBeNull();
});
