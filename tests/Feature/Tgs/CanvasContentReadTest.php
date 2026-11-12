<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class CanvasContentReadTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reads_group_content_without_any_column(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content',
            ['content' => '<p>Привет</p>'],
        );

        $content = $demo->fresh()->getContent();

        $this->assertIsArray($content);
        $this->assertCount(1, $content);
    }

    #[Test]
    public function an_empty_group_reads_as_null(): void
    {
        $demo = TgsDemo::factory()->create();

        $this->assertNull($demo->getContent());
    }

    #[Test]
    public function it_extracts_plain_text_from_the_assembled_tree(): void
    {
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');

        app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content',
            ['content' => '<p>Привет мир</p>'],
        );

        $this->assertStringContainsString('Привет мир', (string) $demo->fresh()->getTextContent());
    }
}
