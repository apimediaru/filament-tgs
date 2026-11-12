<?php

namespace ApiMedia\Tgs\Models\Concerns;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Support\TgsMediaConfiguration;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

use function in_array;

trait HasContentMediaUploads
{
    protected static $contentMediaCollection = 'content_builder';

    public function initializeHasContentMediaUploads(): void
    {
        $this->registerContentMediaCollections();
    }

    public static function getContentMediaCollectionName(): string
    {
        return static::$contentMediaCollection;
    }

    /**
     * Удаляет из content-коллекции media, чьих uuid нет среди $keepUuids — осиротевшие
     * после удаления блоков. deleteAbandonedFiles чистит только переживших блоков; для
     * удалённых компонента-загрузчика уже нет, поэтому их media остаются навсегда.
     *
     * @param  list<string>  $keepUuids
     */
    public function pruneContentMediaExcept(array $keepUuids): void
    {
        // Сбрасываем кэш связи media: saved-хук может работать с инстансом, закэшировавшим
        // связь до addMedia() (например, при create()), — иначе prune смотрит на устаревший список.
        $this->unsetRelation('media');

        $this->getMedia(static::getContentMediaCollectionName())
            ->reject(fn (SpatieMedia $media): bool => in_array($media->getCustomProperty('uuid'), $keepUuids, true))
            ->each(fn (SpatieMedia $media) => $media->delete());
    }

    protected function registerContentMediaCollections(): void
    {
        $this
            ->addMediaCollection(static::getContentMediaCollectionName())
            ->registerMediaConversions(function (?Media $media = null) {
                if ($this instanceof Block && app(TgsMediaConfiguration::class)->register($this, $media)) {
                    return;
                }

                $this->addMediaConversion('tiny')
                    ->width(32)
                    ->blur(5);

                $this->addMediaConversion('thumb')
                    ->width(180)
                    ->height(180)
                    ->optimize();

                $this->addMediaConversion('medium')
                    ->fit(Fit::Max, 600, 600)
                    ->background('#ffffff')
                    ->optimize();

                $this->addMediaConversion('large')
                    ->fit(Fit::Max, 1920, 1920)
                    ->background('#ffffff')
                    ->optimize();
            });
    }
}
