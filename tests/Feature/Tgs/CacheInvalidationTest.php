<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Events\TgsCacheInvalidated;
use ApiMedia\Tgs\Events\TgsContentChanged;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\Contracts\InvalidatesCache;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsHostRegistry;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Workbench\App\Models\TgsDemo;

class CacheInvalidationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        // migrate:fresh also removes the demo tables loaded by Testbench before it.
        $this->loadMigrationsFrom(__DIR__.'/../../../workbench/database/migrations');
    }

    public static function hostKinds(): array
    {
        return [['named'], ['embedded'], ['host']];
    }

    /** @return array{Model, HostDescriptor} */
    private function createTarget(string $kind): array
    {
        if ($kind === 'host') {
            app(TgsHostRegistry::class)->hosts([CacheInvalidationHost::class]);
            $target = CacheInvalidationHost::create(['name' => 'Cached host']);
            $host = $target->canvasDescriptor('content');
        } else {
            if ($kind === 'embedded') {
                $target = Canvas::createEmbedded();
                Canvas::claim([$target->id], 'audit-owner', ['audit-output']);
            } else {
                config(['tgs.named_canvas_cache_keys.audit' => ['audit-output']]);
                $target = Canvas::named('audit');
            }
            $host = $target->hostDescriptor();
        }
        app(CanvasPersistence::class)->addBlock(
            $host, $host->containerType, $host->containerId, $host->slot, 'content', ['content' => 'old'],
        );
        Cache::put('audit-output', 'old');
        Event::fake([TgsCacheInvalidated::class]);

        return [$target, $host];
    }

    #[Test]
    #[DataProvider('hostKinds')]
    public function deletion_invalidates_cache_only_after_commit(string $kind): void
    {
        [$target, $host] = $this->createTarget($kind);
        DB::transaction(function () use ($target): void {
            $target->delete();
            $this->assertSame('old', Cache::get('audit-output'));
            Event::assertNotDispatched(TgsCacheInvalidated::class);
        });

        $this->assertNull(Cache::get('audit-output'));
        Event::assertDispatchedTimes(TgsCacheInvalidated::class, 1);
        Event::assertDispatched(TgsCacheInvalidated::class, fn ($event): bool => $event->key === 'audit-output' && $event->host == $host);
    }

    #[Test]
    #[DataProvider('hostKinds')]
    public function outer_rollback_preserves_cache_after_a_nested_deletion(string $kind): void
    {
        [$target] = $this->createTarget($kind);
        try {
            DB::transaction(function () use ($target): void {
                DB::transaction(fn () => $target->delete());
                $this->assertSame('old', Cache::get('audit-output'));
                Event::assertNotDispatched(TgsCacheInvalidated::class);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertNotNull($target->newQuery()->find($target->getKey()));
        $this->assertSame('old', Cache::get('audit-output'));
        Event::assertNotDispatched(TgsCacheInvalidated::class);
    }

    #[Test]
    public function a_change_without_a_transaction_invalidates_immediately(): void
    {
        [, $host] = $this->createTarget('host');
        TgsContentChanged::dispatch($host);
        $this->assertNull(Cache::get('audit-output'));
        Event::assertDispatchedTimes(TgsCacheInvalidated::class, 1);
    }

    #[Test]
    public function missing_and_non_cacheable_hosts_do_not_forget_unrelated_keys(): void
    {
        $host = TgsDemo::create(['name' => 'Uncached'])->canvasDescriptor('content');
        Cache::put('unrelated', 'keep');
        Event::fake([TgsCacheInvalidated::class]);
        TgsContentChanged::dispatch($host);
        TgsContentChanged::dispatch(new HostDescriptor('missing', '1', 'content'));
        $this->assertSame('keep', Cache::get('unrelated'));
        Event::assertNotDispatched(TgsCacheInvalidated::class);
    }

    #[Test]
    public function surviving_hosts_invalidate_both_captured_and_final_claim_keys(): void
    {
        [$canvas, $host] = $this->createTarget('embedded');
        Cache::put('new-output', 'old');
        DB::transaction(function () use ($canvas, $host): void {
            TgsContentChanged::dispatch($host);
            Canvas::claim([$canvas->getKey()], 'audit-owner', ['new-output']);
            $this->assertSame('old', Cache::get('audit-output'));
            $this->assertSame('old', Cache::get('new-output'));
        });
        $this->assertNull(Cache::get('audit-output'));
        $this->assertNull(Cache::get('new-output'));
        Event::assertDispatchedTimes(TgsCacheInvalidated::class, 2);
    }

    #[Test]
    public function shared_block_edits_and_late_media_events_invalidate_all_consumers(): void
    {
        Storage::fake('public');
        config(['tgs.named_canvas_cache_keys' => ['first' => ['first-output'], 'second' => ['second-output']]]);
        $first = Canvas::named('first')->hostDescriptor();
        $second = Canvas::named('second')->hostDescriptor();
        $persistence = app(CanvasPersistence::class);
        $block = $persistence->addBlock($first, $first->containerType, $first->containerId, $first->slot, 'content', ['content' => 'old']);
        $persistence->insertReference($second, $second->containerType, $second->containerId, $second->slot, $block->id);
        Cache::put('first-output', 'old');
        Cache::put('second-output', 'old');
        DB::transaction(function () use ($persistence, $first, $block): void {
            $persistence->editBlockData($first, $block->id, ['content' => 'new']);
            $this->assertSame('old', Cache::get('first-output'));
            $this->assertSame('old', Cache::get('second-output'));
        });
        $this->assertNull(Cache::get('first-output'));
        $this->assertNull(Cache::get('second-output'));

        $media = $block->addMedia(UploadedFile::fake()->image('photo.jpg'))->toMediaCollection();
        Cache::put('first-output', 'old');
        Cache::put('second-output', 'old');
        event(new ConversionHasBeenCompletedEvent($media->fresh(), Conversion::create('late')));
        $this->assertNull(Cache::get('first-output'));
        $this->assertNull(Cache::get('second-output'));
    }
}

class CacheInvalidationHost extends TgsDemo implements InvalidatesCache
{
    public static function tgsHostKey(): string
    {
        return 'cache_invalidation_host';
    }

    public function cacheKeysToInvalidate(): array
    {
        return ['audit-output'];
    }
}
