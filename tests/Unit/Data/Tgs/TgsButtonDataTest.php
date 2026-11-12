<?php

use ApiMedia\Tgs\Data\Shared\TgsButtonData;

it('plucks button keys, absent → null', function () {
    $b = TgsButtonData::fromStored(['button_label' => 'Open', 'button_theme' => 'primary']);
    expect($b->button_label)->toBe('Open')
        ->and($b->button_theme)->toBe('primary')
        ->and($b->button_variant)->toBeNull();
});
