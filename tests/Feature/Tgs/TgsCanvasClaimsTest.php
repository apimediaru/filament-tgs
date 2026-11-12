<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;

class TgsCanvasClaimsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_synchronizes_the_complete_list_and_releases_removed_canvases(): void
    {
        $this->travelTo(now()->startOfSecond());
        $first = Canvas::createEmbedded();
        $second = Canvas::createEmbedded();
        Canvas::claim([$first->id, $first->id, $second->id], 'site.faq', ['public-faq']);
        $this->assertSame('site.faq', $first->fresh()->owner_key);
        $this->assertSame(['public-faq'], $first->fresh()->cacheKeysToInvalidate());
        $this->travel(30)->hours();
        Canvas::claim([$second->id], 'site.faq', ['new-faq']);
        $this->assertNull($first->fresh()->owner_key);
        $this->assertSame([], $first->fresh()->cacheKeysToInvalidate());
        $this->assertTrue($first->fresh()->released_at->equalTo(now()));
        $this->assertSame(['new-faq'], $second->fresh()->cacheKeysToInvalidate());
    }

    #[Test]
    public function it_rejects_a_foreign_claim_before_changing_any_claim(): void
    {
        $owned = Canvas::createEmbedded();
        $foreign = Canvas::createEmbedded();
        Canvas::claim([$owned->id], 'first', ['first-cache']);
        Canvas::claim([$foreign->id], 'second', ['second-cache']);
        try {
            Canvas::claim([$foreign->id], 'first', ['changed']);
            $this->fail('A foreign claim must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('first', $owned->fresh()->owner_key);
            $this->assertNull($owned->fresh()->released_at);
            $this->assertSame(['first-cache'], $owned->fresh()->cacheKeysToInvalidate());
            $this->assertSame('second', $foreign->fresh()->owner_key);
        }
    }

    #[Test]
    public function gc_keeps_a_claimed_canvas_without_looking_up_application_settings(): void
    {
        $this->travel(-30)->hours();
        $canvas = Canvas::createEmbedded();
        Canvas::claim([$canvas->id], 'another.site.feature', ['another-cache']);
        $this->travelBack();
        $this->artisan('tgs:gc-embedded-canvases')->assertSuccessful();
        $this->assertNotNull(Canvas::find($canvas->id));
    }

    #[Test]
    public function released_canvases_get_a_new_window_that_repeated_saves_do_not_extend(): void
    {
        $this->travelTo(now()->startOfSecond());
        $canvas = Canvas::createEmbedded();
        Canvas::claim([$canvas->id], 'owner', ['public']);
        $this->travel(30)->hours();
        Canvas::claim([], 'owner');
        $this->travel(23)->hours();
        Canvas::claim([], 'owner');
        $this->artisan('tgs:gc-embedded-canvases')->assertSuccessful();
        $this->assertNotNull(Canvas::find($canvas->id));
        $this->travel(2)->hours();
        $this->artisan('tgs:gc-embedded-canvases')->assertSuccessful();
        $this->assertNull(Canvas::find($canvas->id));
    }

    #[Test]
    public function empty_owner_keys_are_rejected(): void
    {
        $canvas = Canvas::createEmbedded();
        $this->expectException(ValidationException::class);
        Canvas::claim([$canvas->id], '   ');
    }

    #[Test]
    public function wrong_kind_missing_and_invalid_ids_cannot_release_the_previous_list(): void
    {
        $saved = Canvas::createEmbedded();
        Canvas::claim([$saved->id], 'owner', ['public']);
        foreach ([Canvas::named('not-embedded')->id, 999999, 'not-an-id'] as $invalid) {
            try {
                Canvas::claim([$invalid], 'owner');
                $this->fail('Invalid canvas must be rejected.');
            } catch (ValidationException) {
                $this->assertSame('owner', $saved->fresh()->owner_key);
                $this->assertNull($saved->fresh()->released_at);
            }
        }
    }

    #[Test]
    public function released_and_never_claimed_canvases_do_not_invalidate_an_owners_cache(): void
    {
        $canvas = Canvas::createEmbedded();
        $this->assertSame([], $canvas->cacheKeysToInvalidate());
        Canvas::claim([(string) $canvas->id], 'owner', ['public', 'public']);
        $this->assertSame(['public'], $canvas->fresh()->cacheKeysToInvalidate());
        Canvas::claim([], 'owner');
        $this->assertSame([], $canvas->fresh()->cacheKeysToInvalidate());
    }
}
