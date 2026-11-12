<?php

namespace ApiMedia\Tgs\Listeners;

use ApiMedia\Tgs\Support\TgsMedia;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

/**
 * Замеряет и кэширует размеры + content-hash каждой конверсии (и оригинала) в
 * custom_properties['conversions'] в момент готовности файла. Дальше read-путь берёт их
 * оттуда без обращения к файлу — это делает рендер диск-агностичным и без I/O.
 * Авто-регистрируется по типу в handle() (как InvalidateAdvertisementCache).
 */
class CaptureMediaConversionData
{
    public function handle(MediaHasBeenAddedEvent|ConversionHasBeenCompletedEvent $event): void
    {
        $media = $event->media;

        $conversion = $event instanceof ConversionHasBeenCompletedEvent
            ? $event->conversion->getName()
            : '';

        TgsMedia::captureConversionData($media, $conversion);
    }
}
