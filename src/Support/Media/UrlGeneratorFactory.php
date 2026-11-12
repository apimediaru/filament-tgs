<?php

namespace ApiMedia\Tgs\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Spatie\MediaLibrary\Support\UrlGenerator\UrlGenerator;
use Spatie\MediaLibrary\Support\UrlGenerator\UrlGeneratorFactory as BaseUrlGeneratorFactory;

use function app;
use function config;

class UrlGeneratorFactory extends BaseUrlGeneratorFactory
{
    public static function createForMedia(Media $media, string $conversionName = ''): UrlGenerator
    {
        $urlGeneratorClass = config('media-library.url_generator');

        static::guardAgainstInvalidUrlGenerator($urlGeneratorClass);

        /** @var UrlGenerator $urlGenerator */
        $urlGenerator = app($urlGeneratorClass);

        $pathGenerator = PathGeneratorFactory::create($media);

        $urlGenerator
            ->setMedia($media)
            ->setPathGenerator($pathGenerator);

        if ($conversionName !== '') {
            $conversion = ConversionCollection::createForMedia($media)->getByName($conversionName);

            $urlGenerator->setConversion($conversion);
        }

        return $urlGenerator;
    }
}
