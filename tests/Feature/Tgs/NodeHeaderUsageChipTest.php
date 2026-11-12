<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TgsUiTestCase as TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;

/**
 * Node header priority/compaction plan, Task A: the three independent usage/reference
 * indicators (usage-count/foreign-badge/shared-badge) merge into one compact clickable
 * chip — icon + number, detail moved to a tooltip — instead of a full sentence inline.
 */
class NodeHeaderUsageChipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Extracts the inner text (tags stripped) of the first element carrying the given
     * data-testid, so assertions target the chip's own rendered content rather than the
     * whole HTML document.
     */
    private function chipText(string $html, string $testid): ?string
    {
        if (! preg_match('/<button[^>]*data-testid="'.preg_quote($testid, '/').'"[^>]*>(.*?)<\/button>/s', $html, $matches)) {
            return null;
        }

        return trim(strip_tags($matches[1]));
    }

    #[Test]
    public function a_reused_owned_block_shows_the_neutral_usage_chip_with_the_count(): void
    {
        $owner = TgsDemo::factory()->create();
        $consumer = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $consumerHost = HostDescriptor::forModelSlot($consumer, 'content');

        $persistence = app(CanvasPersistence::class);
        $block = $persistence->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'content', ['content' => 'shared']);
        $persistence->insertReference($consumerHost, $consumerHost->containerType, $consumerHost->containerId, $consumerHost->slot, $block->id);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $owner->canvas('content')->id])->html();

        $this->assertStringContainsString('data-testid="usage-count"', $html);
        // Compact chip — just the number, the old full sentence no longer renders inline.
        $this->assertStringNotContainsString(trans_choice('tgs::filament.tgs.canvas.usage.count', 2, ['count' => 2]), $html);
        $this->assertSame('2', $this->chipText($html, 'usage-count'));
    }

    #[Test]
    public function a_foreign_reference_shows_the_accent_chip_not_the_neutral_one(): void
    {
        $libraryHost = Canvas::named(TgsNamedCanvas::BlockLibrary)->hostDescriptor();
        $consumer = TgsDemo::factory()->create();
        $consumerHost = HostDescriptor::forModelSlot($consumer, 'content');

        $persistence = app(CanvasPersistence::class);
        $block = $persistence->addBlock($libraryHost, $libraryHost->containerType, $libraryHost->containerId, $libraryHost->slot, 'content', ['content' => 'shared']);
        $persistence->insertReference($consumerHost, $consumerHost->containerType, $consumerHost->containerId, $consumerHost->slot, $block->id);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $consumer->canvas('content')->id])->html();

        $this->assertStringContainsString('data-testid="foreign-badge"', $html);
        $this->assertStringNotContainsString('data-testid="usage-count"', $html);
        $this->assertSame('2', $this->chipText($html, 'foreign-badge'));
    }

    #[Test]
    public function the_usage_chip_never_overlaps_the_preview_text_regression(): void
    {
        $owner = TgsDemo::factory()->create();
        $consumer = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $consumerHost = HostDescriptor::forModelSlot($consumer, 'content');

        $persistence = app(CanvasPersistence::class);
        $block = $persistence->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'content', ['content' => 'shared']);
        $persistence->insertReference($consumerHost, $consumerHost->containerType, $consumerHost->containerId, $consumerHost->slot, $block->id);
        Block::whereKey($block->id)->update(['is_locked' => true]);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $owner->canvas('content')->id])->html();

        $this->assertStringContainsString('data-testid="usage-count"', $html);
        $this->assertStringContainsString('data-testid="lock-badge"', $html);

        preg_match_all('/<[a-z]+([^>]*data-testid="(?:usage-count|foreign-badge|shared-badge|lock-badge)"[^>]*)>/', $html, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $attributes) {
            $this->assertStringContainsString('shrink-0', $attributes, "expected shrink-0 on: {$attributes}");
        }
    }

    #[Test]
    public function a_child_does_not_repeat_the_parent_container_usage_count(): void
    {
        $owner = TgsDemo::factory()->create();
        $consumer = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $consumerHost = HostDescriptor::forModelSlot($consumer, 'content');

        $persistence = app(CanvasPersistence::class);
        $column = $persistence->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'column', []);
        // The child is placed once, only inside the column — its own usagePlacesCount is
        // entirely INHERITED from the column's root resolution, not independently earned.
        $persistence->addBlock($ownerHost, 'block', $column->id, 'content', 'content', ['content' => 'nested']);
        $persistence->insertReference($consumerHost, $consumerHost->containerType, $consumerHost->containerId, $consumerHost->slot, $column->id);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $owner->canvas('content')->id])->html();

        // Only the column (the parent) shows the chip; the child does not repeat it.
        $this->assertSame(1, substr_count($html, 'data-testid="usage-count"'));
    }

    #[Test]
    public function a_child_shows_its_own_usage_count_when_it_differs_from_the_parent(): void
    {
        $owner = TgsDemo::factory()->create();
        $consumer = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $consumerHost = HostDescriptor::forModelSlot($consumer, 'content');

        $persistence = app(CanvasPersistence::class);
        // Column itself is used only once (not reused) — no chip expected on it.
        $column = $persistence->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'column', []);
        $child = $persistence->addBlock($ownerHost, 'block', $column->id, 'content', 'content', ['content' => 'nested']);
        // But the CHILD is independently reused on another host — its own count (2) differs
        // from the column's (1), so it must show its own chip.
        $persistence->insertReference($consumerHost, $consumerHost->containerType, $consumerHost->containerId, $consumerHost->slot, $child->id);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $owner->canvas('content')->id])->html();

        $this->assertSame(1, substr_count($html, 'data-testid="usage-count"'));
        $this->assertSame('2', $this->chipText($html, 'usage-count'));
    }

    #[Test]
    public function a_reference_chip_shows_even_when_the_count_matches_the_parent(): void
    {
        $owner = TgsDemo::factory()->create();
        $consumer = TgsDemo::factory()->create();
        $ownerHost = HostDescriptor::forModelSlot($owner, 'content');
        $consumerHost = HostDescriptor::forModelSlot($consumer, 'content');

        $persistence = app(CanvasPersistence::class);
        $column = $persistence->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'column', []);
        // A block whose ORIGIN is a root placement on the same host as the column...
        $shared = $persistence->addBlock($ownerHost, $ownerHost->containerType, $ownerHost->containerId, $ownerHost->slot, 'content', ['content' => 'origin']);
        // ...and which is ALSO referenced a second time, nested inside the column. That
        // nested placement's root resolution is exactly the column's own roots (owner +
        // consumer, once the column is referenced below) — same usagePlacesCount as the
        // column, yet it must still show its accent chip (never deduped).
        $persistence->insertReference($ownerHost, 'block', $column->id, 'content', $shared->id);
        $persistence->insertReference($consumerHost, $consumerHost->containerType, $consumerHost->containerId, $consumerHost->slot, $column->id);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $owner->canvas('content')->id])->html();

        $this->assertStringContainsString('data-testid="shared-badge"', $html);
        $this->assertSame('2', $this->chipText($html, 'shared-badge'));
    }

    #[Test]
    public function the_origin_shows_a_usage_chip_when_referenced_a_second_time_on_the_same_host(): void
    {
        // insertFromToken()'s "as reference" option lets a host paste a block's own id back
        // into a slot on the SAME page it already owns — usagePlacesCount() (host-count
        // based, CanvasPersistence::resolveUsagePlaces()) collapses both placements into a
        // single root host, so the ORIGIN placement's usagePlacesCount() stays 1 and never
        // clears the old `> 1` gate — the origin showed no chip at all even though a second,
        // visibly-badged placement of the exact same block exists right below it. The chip
        // must gate on the total LIVE PLACEMENT count instead (blockPlacementsCount()), which
        // isn't collapsed by shared hosts.
        $demo = TgsDemo::factory()->create();
        $host = HostDescriptor::forModelSlot($demo, 'content');
        $persistence = app(CanvasPersistence::class);

        $origin = $persistence->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'text']);
        $persistence->insertReference($host, $host->containerType, $host->containerId, $host->slot, $origin->id);

        $html = Livewire::test(TgsCanvas::class, ['canvasId' => $demo->canvas('content')->id])->html();

        $this->assertSame(1, substr_count($html, 'data-testid="usage-count"'), 'the origin should show its own neutral chip');
        $this->assertSame(1, substr_count($html, 'data-testid="shared-badge"'), 'the later placement keeps its accent chip');
        $this->assertSame('2', $this->chipText($html, 'usage-count'));
    }
}
