<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

class CanvasTreeReplacementMediaPruneTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owned_picture_block_prunes_media_not_matching_its_uuid(): void
    {
        $host = new HostDescriptor('news', '1', 'content_builder_value');
        $persistence = app(CanvasPersistence::class);

        $persistence->replaceTree($host, [
            ['type' => 'picture', 'data' => ['id' => 'PIC', 'uuid' => 'keep-me']],
        ]);

        $block = Block::find('PIC');
        $block->addMedia(UploadedFile::fake()->image('keep.jpg'))
            ->withCustomProperties(['uuid' => 'keep-me'])
            ->toMediaCollection('content_builder');
        $block->addMedia(UploadedFile::fake()->image('stale.jpg'))
            ->withCustomProperties(['uuid' => 'stale-uuid'])
            ->toMediaCollection('content_builder');

        // Пересохраняем тот же блок с тем же uuid — stale-uuid должен уйти.
        $persistence->replaceTree($host, [
            ['type' => 'picture', 'data' => ['id' => 'PIC', 'uuid' => 'keep-me']],
        ]);

        $block->refresh();
        $uuids = $block->getMedia('content_builder')->pluck('custom_properties.uuid')->all();

        $this->assertContains('keep-me', $uuids);
        $this->assertNotContains('stale-uuid', $uuids);
    }

    #[Test]
    public function non_owning_host_never_prunes_a_foreign_blocks_media(): void
    {
        $owner = new HostDescriptor('news', '1', 'content_builder_value');
        $persistence = app(CanvasPersistence::class);

        $persistence->replaceTree($owner, [
            ['type' => 'picture', 'data' => ['id' => 'SHARED', 'uuid' => 'owner-uuid']],
        ]);

        $block = Block::find('SHARED');
        $block->addMedia(UploadedFile::fake()->image('owner.jpg'))
            ->withCustomProperties(['uuid' => 'owner-uuid'])
            ->toMediaCollection('content_builder');

        $stranger = new HostDescriptor('page', '7', 'template_data.builder');
        $persistence->replaceTree($stranger, [
            ['type' => 'picture', 'data' => ['id' => 'SHARED', 'uuid' => 'tampered']],
        ]);

        $block->refresh();
        $this->assertContains('owner-uuid', $block->getMedia('content_builder')->pluck('custom_properties.uuid')->all());
    }
}
