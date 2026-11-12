<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class DuplicateMediaCopyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function duplicating_a_picture_copies_its_media_independently(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        $picture = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'picture', ['uuid' => 'src-uuid']);
        $picture->addMedia(UploadedFile::fake()->image('src.jpg'))
            ->withCustomProperties(['uuid' => 'src-uuid'])
            ->toMediaCollection('content_builder');

        $clone = $p->duplicateBlock($host, $host->containerType, $host->containerId, $host->slot, $picture->id);

        // У копии — своя media-запись в своей коллекции.
        $this->assertSame(1, $clone->getMedia('content_builder')->count());
        // uuid копии независим (не тот же, что у исходника) — гарантия независимости.
        $this->assertNotSame('src-uuid', $clone->fresh()->data['uuid']);
        // Исходник свою медиа сохранил.
        $this->assertSame(1, $picture->fresh()->getMedia('content_builder')->count());
        // data['uuid'] клона и custom_properties.uuid его медиа синхронизированы —
        // иначе read-пайплайн (PictureData::resolveImage) не сможет отфильтровать
        // media клона обратно по uuid, и она станет недостижимым сиротой.
        $this->assertSame(
            $clone->fresh()->data['uuid'],
            $clone->getMedia('content_builder')->first()->getCustomProperty('uuid'),
        );
    }

    #[Test]
    public function media_is_copied_at_any_depth(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        $col = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $picture = $p->addBlock($host, 'block', $col->id, 'content', 'picture', ['uuid' => 'deep-uuid']);
        $picture->addMedia(UploadedFile::fake()->image('d.jpg'))
            ->withCustomProperties(['uuid' => 'deep-uuid'])->toMediaCollection('content_builder');

        $clone = $p->duplicateBlock($host, $host->containerType, $host->containerId, $host->slot, $col->id);

        $deepClonedId = BlockPlacement::query()->where('container_id', $clone->id)->where('slot', 'content')->value('block_id');
        $this->assertSame(1, Block::find($deepClonedId)->getMedia('content_builder')->count());
    }

    #[Test]
    public function when_source_data_has_no_uuid_field_clone_media_custom_property_is_left_untouched(): void
    {
        // Edge case: block has media but its `data` has no valid `uuid` field
        // (already a pre-existing mismatch on the source itself — its media
        // custom_properties.uuid doesn't match anything in `data` either).
        // The fix must not make things worse: it must not rewrite the copied
        // media's custom_properties.uuid to a *new* value that is written
        // nowhere in the clone's data (that would be a freshly introduced
        // desync == silent orphan for the read pipeline). Rewriting
        // data['uuid'] and custom_properties.uuid must stay a single atomic
        // guard — neither happens here, so the copied media keeps exactly
        // the custom_properties.uuid it was copied with.
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $p = app(CanvasPersistence::class);

        $picture = $p->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'picture', []);
        $picture->addMedia(UploadedFile::fake()->image('src.jpg'))
            ->withCustomProperties(['uuid' => 'src-uuid'])
            ->toMediaCollection('content_builder');

        $clone = $p->duplicateBlock($host, $host->containerType, $host->containerId, $host->slot, $picture->id);

        $cloneMediaUuid = $clone->getMedia('content_builder')->first()->getCustomProperty('uuid');

        // Unchanged: still the value copied over from the source media, not
        // some freshly generated $newUuid written nowhere in clone data.
        $this->assertSame('src-uuid', $cloneMediaUuid);
    }
}
