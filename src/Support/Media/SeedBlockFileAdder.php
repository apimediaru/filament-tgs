<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Support\Media;

use ApiMedia\Tgs\Models\Block;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * @internal
 *
 * @extends FileAdder<Media>
 */
final class SeedBlockFileAdder extends FileAdder
{
    protected function attachMedia(Media $media): void
    {
        /** @var Block $block */
        $block = $this->subject;
        $media->setRelation('model', $block);

        // Spatie can throw after copying the file and before returning the media.
        $block->getConnection()->afterRollBack(static function () use ($media): void {
            if ($media->getKey() === null) {
                return;
            }

            try {
                self::removeFiles($media);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        parent::attachMedia($media);
    }

    private static function removeFiles(Media $media): void
    {
        $filesystem = app(Filesystem::class);
        $filesystem->removeAllFiles($media);

        // A prefix like "/media" makes Spatie's conversion paths disagree with the disk listing.
        if (str_starts_with($filesystem->getConversionDirectory($media), '/')) {
            $paths = array_map(
                static fn (string $name): string => ltrim($media->getPathRelativeToRoot($name), '/'),
                $media->getMediaConversionNames(),
            );
            Storage::disk($media->conversions_disk)->delete($paths);
        }
    }
}
