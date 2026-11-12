<?php

namespace ApiMedia\Tgs\Models;

use ApiMedia\Tgs\Support\Media\ConversionCollection;
use ApiMedia\Tgs\Support\Media\UrlGeneratorFactory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Separate media table: model_id stores the block ULID rather than an integer host key. */
class BlockMedia extends Media
{
    protected $table = 'tgs_block_media';

    public function getMediaConversionNames(): array
    {
        return ConversionCollection::createForMedia($this)->getNames();
    }

    public function getUrl(string $conversionName = ''): string
    {
        return UrlGeneratorFactory::createForMedia($this, $conversionName)->getUrl();
    }
}
