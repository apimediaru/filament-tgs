<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

class TgsDemoTgsGroupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_a_tgs_demo_auto_creates_its_content_group_pointing_at_the_existing_root(): void
    {
        $demo = TgsDemo::factory()->create();

        $group = $demo->canvas('content');
        $descriptor = $group->hostDescriptor();
        $expected = HostDescriptor::forModelSlot($demo, 'content');

        $this->assertSame($expected->containerType, $descriptor->containerType);
        $this->assertSame($expected->containerId, $descriptor->containerId);
        $this->assertSame($expected->slot, $descriptor->slot);
    }
}
