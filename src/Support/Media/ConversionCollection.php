<?php

namespace ApiMedia\Tgs\Support\Media;

use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection as BaseConversionCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

use function array_map;

class ConversionCollection extends BaseConversionCollection
{
    public static function createForMedia(Media $media): static
    {
        return (new static)->setMedia($media);
    }

    public function setMedia(Media $media): static
    {
        parent::setMedia($media);

        $this->removeUnrelatedConversions();

        return $this;
    }

    protected function removeUnrelatedConversions()
    {
        // В исходниках поиск осуществляется во всех конверсиях без учета коллекции медиа. Это приводит к ошибкам,
        // если в разных коллекциях есть конверсии с одинаковыми названиями.
        // Например, при генерации URL расширение файла может формироваться на основе конверсии
        // совершенно другой коллекции, что приводит к 404 ошибкам при попытке получить URL несуществующего файла.
        // Поэтому идея исправлениям в том, чтобы оставить только конверсии текущей модели и её коллекции.
        $this->items = $this
            ->filter(
                fn (Conversion $conversion) => $conversion->shouldBePerformedOn($this->media->collection_name)
            )
            ->all();
    }

    public function getNames()
    {
        return array_map(fn (Conversion $conversion) => $conversion->getName(), $this->items);
    }
}
