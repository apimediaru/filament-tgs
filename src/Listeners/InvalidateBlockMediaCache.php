<?php

namespace ApiMedia\Tgs\Listeners;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\TgsContentChangeNotifier;
use ApiMedia\Tgs\Support\HostDescriptor;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

final class InvalidateBlockMediaCache
{
    public function __construct(private readonly TgsContentChangeNotifier $notifier) {}

    public function handle(MediaHasBeenAddedEvent|ConversionHasBeenCompletedEvent $event): void
    {
        $block = $event->media->model;
        if ($block instanceof Block) {
            $this->notifier->notify(new HostDescriptor($block->owner_container_type, $block->owner_container_id, ''), [$block->id]);
        }
    }
}
