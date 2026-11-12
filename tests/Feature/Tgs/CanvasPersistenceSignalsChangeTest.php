<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Events\TgsContentChanged;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Tests\Support\Tgs\CanvasPersistenceMutators;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Workbench\App\Models\TgsDemo;

use function array_slice;
use function file;
use function implode;
use function str_contains;

class CanvasPersistenceSignalsChangeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_mutating_method_calls_signal_changed(): void
    {
        $missing = [];

        foreach (CanvasPersistenceMutators::names() as $name) {
            $method = new ReflectionMethod(CanvasPersistence::class, $name);
            $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            if (! str_contains($source, 'signalChanged(')) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'CanvasPersistence mutating method(s) with no signalChanged(): '
            .implode(', ', $missing).'. Add $this->signalChanged($host, [...]); or, if genuinely non-mutating, '
            .'add to ApiMedia\Tgs\Tests\Support\Tgs\CanvasPersistenceMutators::NON_MUTATING with a reason.');
    }

    #[Test]
    public function editing_a_block_dispatches_tgs_content_changed(): void
    {
        Event::fake([TgsContentChanged::class]);
        $demo = TgsDemo::factory()->create();
        $host = $demo->canvas('content')->hostDescriptor();
        $block = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'x']);

        app(CanvasPersistence::class)->editBlockData($host, $block->id, ['content' => 'y']);

        Event::assertDispatched(TgsContentChanged::class);
    }
}
