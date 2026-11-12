<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasTreeReader;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Regression: Picture/Gallery::getBlockFields() Hidden('uuid')->default(...) never fired
 * because editBlockAction()->fillForm() always supplies a non-null array (even for a
 * brand-new draft block, where $block->data === []) — and Filament's default() is only
 * applied by hydrateDefaultState() when the schema's fill() state is exactly null. The
 * missing 'uuid' key was instead filled with plain null by fillStateWithNull(), so every
 * Picture/Gallery block added through the canvas had data['uuid'] === null regardless of
 * whether an image was attached.
 */
class PictureGalleryUuidHydrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function picture_block_gets_a_non_null_uuid_immediately_after_auto_open(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->call('addRootBlock', 'picture')->assertSuccessful();

        $uuid = $component->get('mountedActions.0.data.uuid');

        $this->assertNotNull($uuid);
        $this->assertNotSame('', $uuid);
    }

    #[Test]
    public function gallery_block_gets_a_non_null_uuid_immediately_after_auto_open(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->call('addRootBlock', 'gallery')->assertSuccessful();

        $uuid = $component->get('mountedActions.0.data.uuid');

        $this->assertNotNull($uuid);
        $this->assertNotSame('', $uuid);
    }

    #[Test]
    public function uploading_a_picture_through_the_canvas_produces_a_non_empty_preview_line(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->call('addRootBlock', 'picture')->assertSuccessful();

        $uuid = $component->get('mountedActions.0.data.uuid');

        $component->fillForm([
            'image' => [UploadedFile::fake()->image('a.jpg')],
            'alt' => 'Кошка',
        ])->callMountedAction();

        $tree = app(CanvasTreeReader::class)->read($host);

        $this->assertSame(
            __('tgs::filament.tgs.blocks.picture.preview_with_alt', ['alt' => 'Кошка']),
            $tree[0]['previewLine'],
        );

        $block = Block::query()->where('owner_container_id', (string) $demo->id)->sole();
        $this->assertNotEmpty($block->getMedia(Block::getContentMediaCollectionName()));
        $this->assertSame($uuid, $block->data['uuid']);
    }

    #[Test]
    public function uploading_two_images_to_a_gallery_through_the_canvas_produces_a_count_preview(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->call('addRootBlock', 'gallery')->assertSuccessful();

        $component->fillForm([
            'image' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
            ],
        ])->callMountedAction();

        $tree = app(CanvasTreeReader::class)->read($host);

        $this->assertSame(
            __('tgs::filament.tgs.blocks.gallery.preview', ['count' => 2]),
            $tree[0]['previewLine'],
        );
    }

    /**
     * Regression: CanvasPersistence::pruneOrphanedMedia() only prunes when
     * isset($data['uuid']) — and isset(null) === false, so as long as data['uuid'] hydrated
     * to null (the bug fixed above), stale media attached under a different uuid was never
     * pruned for a Picture/Gallery block edited through the real canvas flow.
     */
    #[Test]
    public function reopening_and_resubmitting_a_picture_through_the_canvas_prunes_stale_media(): void
    {
        $demo = TgsDemo::factory()->create();
        $group = $demo->canvas('content');
        $host = $group->hostDescriptor();

        $component = Livewire::test(TgsCanvas::class, ['canvasId' => $group->id]);
        $component->call('addRootBlock', 'picture')->assertSuccessful();

        $blockId = Block::query()->where('owner_container_id', (string) $demo->id)->sole()->id;
        $uuid = $component->get('mountedActions.0.data.uuid');

        $block = Block::find($blockId);
        $block->addMedia(UploadedFile::fake()->image('stale.jpg'))
            ->withCustomProperties(['uuid' => 'stale-uuid'])
            ->toMediaCollection(Block::getContentMediaCollectionName());

        $component->fillForm([
            'image' => [UploadedFile::fake()->image('fresh.jpg')],
        ])->callMountedAction();

        $block->refresh();
        $mediaUuids = $block->getMedia(Block::getContentMediaCollectionName())
            ->pluck('custom_properties.uuid')->all();

        $this->assertSame([$uuid], $mediaUuids);

        $tree = app(CanvasTreeReader::class)->read($host);
        $this->assertSame(__('tgs::filament.tgs.blocks.picture.preview'), $tree[0]['previewLine']);
    }
}
