<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Blocks\Gallery;
use ApiMedia\Tgs\Blocks\Picture;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Services\CanvasTreeReader;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class BlockPreviewLineTest extends TestCase
{
    use RefreshDatabase;

    private function makeBlock(string $type): Block
    {
        return Block::create([
            'id' => (string) Str::ulid(),
            'type' => $type,
            'data' => [],
            'owner_container_type' => 'news',
            'owner_container_id' => '1',
        ]);
    }

    #[Test]
    public function picture_reports_no_image_when_nothing_is_attached(): void
    {
        $block = $this->makeBlock('picture');

        $this->assertSame(
            __('tgs::filament.tgs.blocks.picture.preview_empty'),
            Picture::make()->blockPreview(['uuid' => 'abc'], $block),
        );
        $this->assertSame(
            __('tgs::filament.tgs.blocks.picture.preview_empty'),
            Picture::make()->blockPreview([], $block),
        );
    }

    #[Test]
    public function picture_reports_has_image_when_media_is_attached_without_alt_or_title(): void
    {
        $block = $this->makeBlock('picture');
        $block->addMedia(UploadedFile::fake()->image('a.jpg'))
            ->withCustomProperties(['uuid' => 'abc'])
            ->toMediaCollection(Block::getContentMediaCollectionName());

        $this->assertSame(
            __('tgs::filament.tgs.blocks.picture.preview'),
            Picture::make()->blockPreview(['uuid' => 'abc'], $block),
        );
    }

    #[Test]
    public function picture_reports_alt_when_media_is_attached_with_alt(): void
    {
        $block = $this->makeBlock('picture');
        $block->addMedia(UploadedFile::fake()->image('a.jpg'))
            ->withCustomProperties(['uuid' => 'abc'])
            ->toMediaCollection(Block::getContentMediaCollectionName());

        $this->assertSame(
            __('tgs::filament.tgs.blocks.picture.preview_with_alt', ['alt' => 'Кошка']),
            Picture::make()->blockPreview(['uuid' => 'abc', 'alt' => 'Кошка'], $block),
        );
    }

    #[Test]
    public function picture_falls_back_to_title_when_alt_is_blank(): void
    {
        $block = $this->makeBlock('picture');
        $block->addMedia(UploadedFile::fake()->image('a.jpg'))
            ->withCustomProperties(['uuid' => 'abc'])
            ->toMediaCollection(Block::getContentMediaCollectionName());

        $this->assertSame(
            __('tgs::filament.tgs.blocks.picture.preview_with_alt', ['alt' => 'Заголовок']),
            Picture::make()->blockPreview(['uuid' => 'abc', 'alt' => '', 'title' => 'Заголовок'], $block),
        );
    }

    #[Test]
    public function gallery_reports_no_images_when_nothing_is_attached(): void
    {
        $block = $this->makeBlock('gallery');

        $this->assertSame(
            __('tgs::filament.tgs.blocks.gallery.preview_empty'),
            Gallery::make()->blockPreview(['uuid' => 'abc'], $block),
        );
    }

    #[Test]
    public function gallery_reports_the_real_attached_media_count(): void
    {
        $block = $this->makeBlock('gallery');
        $block->addMedia(UploadedFile::fake()->image('a.jpg'))
            ->withCustomProperties(['uuid' => 'abc'])
            ->toMediaCollection(Block::getContentMediaCollectionName());
        $block->addMedia(UploadedFile::fake()->image('b.jpg'))
            ->withCustomProperties(['uuid' => 'abc'])
            ->toMediaCollection(Block::getContentMediaCollectionName());

        $this->assertSame(
            __('tgs::filament.tgs.blocks.gallery.preview', ['count' => 2]),
            Gallery::make()->blockPreview(['uuid' => 'abc', 'image' => []], $block),
        );
    }

    #[Test]
    public function a_container_with_children_reports_a_child_count(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $persistence = app(CanvasPersistence::class);

        $col = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'column', []);
        $persistence->addBlock($host, 'block', $col->id, 'content', 'content', []);

        $tree = app(CanvasTreeReader::class)->read($host);
        $node = collect($tree)->firstWhere('id', $col->id);

        $this->assertSame(1, $node['childCount']);
    }
}
