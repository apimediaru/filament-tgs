<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\Stubs\Models\CanvasedHostStub;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Слайс A (Этап 1 «канвасы как сущность»): mirrors the old (now-removed) HasTgsGroupsTest, exercising
 * ApiMedia\Tgs\Models\Concerns\HasCanvases against a plain stub host — before any real production
 * consumer (Page/News/Article/...) switches over in Слайс B.
 */
class HasCanvasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CanvasedHostStub::createTable();
    }

    protected function tearDown(): void
    {
        CanvasedHostStub::dropTable();
        parent::tearDown();
    }

    #[Test]
    public function a_model_declares_its_canvas_groups(): void
    {
        $this->assertSame(['description', 'room_1', 'room_2'], CanvasedHostStub::canvasGroups());
    }

    #[Test]
    public function a_group_resolves_to_a_host_descriptor_bound_to_that_root_slot(): void
    {
        $model = new CanvasedHostStub;
        $model->id = 42;

        $descriptor = $model->canvasDescriptor('room_1');

        $this->assertInstanceOf(HostDescriptor::class, $descriptor);
        $this->assertSame('42', $descriptor->containerId);
        $this->assertSame('room_1', $descriptor->slot);
    }

    #[Test]
    public function an_unknown_group_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CanvasedHostStub)->canvasDescriptor('bathroom');
    }

    #[Test]
    public function creating_a_host_auto_creates_a_canvas_per_declared_key(): void
    {
        $host = CanvasedHostStub::create([]);

        $keys = $host->canvasRecords()->pluck('group')->sort()->values()->all();
        $this->assertSame(['description', 'room_1', 'room_2'], $keys);
        $this->assertTrue($host->canvasRecords()->get()->every(fn ($c) => $c->kind === 'host'));
    }

    #[Test]
    public function canvas_returns_the_record_for_a_declared_key(): void
    {
        $host = CanvasedHostStub::create([]);

        $canvas = $host->canvas('room_1');
        $this->assertSame('room_1', $canvas->group);
        $this->assertTrue($canvas->host->is($host));
    }

    #[Test]
    public function canvas_rejects_an_unknown_key(): void
    {
        $host = CanvasedHostStub::create([]);
        $this->expectException(InvalidArgumentException::class);
        $host->canvas('bathroom');
    }

    #[Test]
    public function deleting_a_host_deletes_its_canvases(): void
    {
        $host = CanvasedHostStub::create([]);
        $this->assertDatabaseCount('tgs_canvases', 3);

        $host->delete();
        $this->assertDatabaseCount('tgs_canvases', 0);
    }
}
