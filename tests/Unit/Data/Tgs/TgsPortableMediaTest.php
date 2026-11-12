<?php

use ApiMedia\Tgs\Data\Shared\TgsImageData;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

it('reads stored dimensions from a base Spatie media model without touching its file', function () {
    $media = new class extends Media
    {
        public function getMediaConversionNames(): array
        {
            return ['banner'];
        }

        public function getUrl(string $conversionName = ''): string
        {
            return '/'.($conversionName ?: 'original');
        }

        public function stream(string $conversionName = '')
        {
            throw new RuntimeException('Must use metadata.');
        }
    };
    $media->setCustomProperty('conversions', ['' => ['width' => 600, 'height' => 300], 'banner' => ['width' => 300, 'height' => 100]]);
    $image = TgsImageData::fromMedia($media);
    expect($image->original->width)->toBe(600)->and($image->conversions['banner']->width)->toBe(300);
});
