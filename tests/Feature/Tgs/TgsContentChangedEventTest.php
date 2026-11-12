<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Events\TgsContentChanged;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class TgsContentChangedEventTest extends TestCase
{
    #[Test]
    public function it_carries_the_affected_host_descriptor(): void
    {
        Event::fake();

        $host = HostDescriptor::forSettings('company.about');
        TgsContentChanged::dispatch($host);

        Event::assertDispatched(TgsContentChanged::class, function (TgsContentChanged $e) use ($host): bool {
            return $e->host->containerType === $host->containerType
                && $e->host->containerId === $host->containerId
                && $e->host->slot === $host->slot;
        });
    }
}
