<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Events\TgsCacheInvalidated;
use ApiMedia\Tgs\Events\TgsContentChanged;
use ApiMedia\Tgs\Models\BlockPlacement;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\Contracts\InvalidatesCache;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\LaravelSiteCacheInvalidator;
use ApiMedia\Tgs\Support\TgsHostRegistry;
use ApiMedia\Tgs\Tests\Fixtures\RecordingSiteCacheInvalidator;
use ApiMedia\Tgs\Tests\TestCase;
use ApiMedia\Tgs\TgsPlugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
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
    private function createTarget(string $kind, bool $fakeInvalidation = true): array
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
        if ($fakeInvalidation) {
            Event::fake([TgsCacheInvalidated::class]);
        }

        return [$target, $host];
    }

    #[Test]
    #[DataProvider('hostKinds')]
    public function deletion_without_an_outer_transaction_does_not_leave_a_refilled_cache_entry(string $kind): void
    {
        [$target] = $this->createTarget($kind, fakeInvalidation: false);
        $invalidations = 0;

        Event::listen(TgsCacheInvalidated::class, function (TgsCacheInvalidated $event) use ($target, &$invalidations): void {
            if ($event->key !== 'audit-output') {
                return;
            }

            $invalidations++;
            if ($target->newQuery()->whereKey($target->getKey())->exists()) {
                Cache::put($event->key, 'refilled from the existing row');
            }
        });

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('old', Cache::get('audit-output'));
        $this->assertTrue($target->delete());
        $this->assertFalse($target->newQuery()->whereKey($target->getKey())->exists());
        $this->assertSame(1, $invalidations);
        $this->assertNull(Cache::get('audit-output'));
    }

    #[Test]
    #[DataProvider('hostKinds')]
    public function a_deletion_veto_rolls_back_the_cascade(string $kind): void
    {
        [$target, $host] = $this->createTarget($kind);
        $target::deleting(fn (): bool => false);

        $this->assertFalse($target->delete());
        $this->assertTrue($target->newQuery()->whereKey($target->getKey())->exists());
        $this->assertTrue(BlockPlacement::query()->where([
            'container_type' => $host->containerType,
            'container_id' => $host->containerId,
            'slot' => $host->slot,
        ])->exists());
        $this->assertSame('old', Cache::get('audit-output'));
        Event::assertNotDispatched(TgsCacheInvalidated::class);
    }

    #[Test]
    public function a_canvas_veto_cancels_deletion_of_its_model_host(): void
    {
        [$target] = $this->createTarget('host');
        Canvas::deleting(fn (): bool => false);

        $this->assertFalse($target->delete());
        $this->assertTrue($target->newQuery()->whereKey($target->getKey())->exists());
        $this->assertSame('old', Cache::get('audit-output'));
        Event::assertNotDispatched(TgsCacheInvalidated::class);
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
    #[TestWith([true])]
    #[TestWith([false])]
    public function a_site_handler_receives_deleted_host_keys_only_after_commit(bool $outerTransaction): void
    {
        [$canvas, $host] = $this->createTarget('named');
        Filament::setCurrentPanel(null);
        Panel::make()->id('site-cache')->plugin(TgsPlugin::make()->siteCache(RecordingSiteCacheInvalidator::class));
        Panel::make()->id('second')->plugin(TgsPlugin::make()->siteCache(RecordingSiteCacheInvalidator::class));
        $handler = new RecordingSiteCacheInvalidator;
        app()->instance(RecordingSiteCacheInvalidator::class, $handler);

        if ($outerTransaction) {
            DB::transaction(function () use ($canvas, $handler): void {
                $canvas->delete();
                $this->assertSame([], $handler->calls);
                $this->assertSame('old', Cache::get('audit-output'));
            });
        } else {
            $canvas->delete();
        }

        $this->assertNull($canvas->newQuery()->find($canvas->getKey()));
        $this->assertEquals([[$host, ['audit-output']]], $handler->calls);
        $this->assertSame('old', Cache::get('audit-output'));
        Event::assertNotDispatched(TgsCacheInvalidated::class);
    }

    public static function conflictingSiteHandlers(): iterable
    {
        yield 'two classes' => [RecordingSiteCacheInvalidator::class, LaravelSiteCacheInvalidator::class];
        yield 'two classes in reverse order' => [LaravelSiteCacheInvalidator::class, RecordingSiteCacheInvalidator::class];
        yield 'class and disabled' => [RecordingSiteCacheInvalidator::class, false];
        yield 'disabled and class' => [false, RecordingSiteCacheInvalidator::class];
    }

    /**
     * @param  class-string<RecordingSiteCacheInvalidator|LaravelSiteCacheInvalidator>|false  $first
     * @param  class-string<RecordingSiteCacheInvalidator|LaravelSiteCacheInvalidator>|false  $second
     */
    #[Test]
    #[DataProvider('conflictingSiteHandlers')]
    public function different_panels_cannot_choose_conflicting_site_cache_modes(string|false $first, string|false $second): void
    {
        Panel::make()->id('first-site-panel')->plugin(TgsPlugin::make()->siteCache($first));
        Panel::make()->id('second-site-panel')->plugin(TgsPlugin::make()->siteCache($second));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Conflicting TGS site cache registrations');
        TgsContentChanged::dispatch(new HostDescriptor('missing', '1', 'content'));
    }

    #[Test]
    public function disabling_site_cache_keeps_the_content_signal_and_block_media_metadata(): void
    {
        Storage::fake('public');
        [$canvas, $host] = $this->createTarget('named');
        Panel::make()->id('site-cache')->plugin(TgsPlugin::make()->siteCache(false));
        $block = app(CanvasPersistence::class)->addBlock($host, 'canvas', (string) $canvas->getKey(), 'content', 'content', []);
        $contentChanges = [];
        Event::listen(TgsContentChanged::class, function (TgsContentChanged $event) use (&$contentChanges): void {
            $contentChanges[] = $event->host;
        });

        $media = $block->addMedia(UploadedFile::fake()->image('photo.jpg', 24, 16))->toMediaCollection();

        $this->assertContainsEquals($host, $contentChanges);
        $this->assertNotEmpty($media->fresh()->getCustomProperty('conversions'));
        $this->assertTrue($canvas->delete());
        $this->assertSame('old', Cache::get('audit-output'));
        Event::assertNotDispatched(TgsCacheInvalidated::class);
    }

    #[Test]
    public function a_site_handler_uses_final_keys_and_discards_rolled_back_changes(): void
    {
        [$canvas, $host] = $this->createTarget('embedded');
        Panel::make()->id('site-cache')->plugin(TgsPlugin::make()->siteCache(RecordingSiteCacheInvalidator::class));
        $handler = new RecordingSiteCacheInvalidator;
        app()->instance(RecordingSiteCacheInvalidator::class, $handler);

        DB::beginTransaction();
        DB::transaction(fn () => $canvas->delete());
        DB::rollBack();
        $this->assertNotNull($canvas->newQuery()->find($canvas->getKey()));
        $this->assertSame([], $handler->calls);

        DB::transaction(function () use ($canvas, $host, $handler): void {
            TgsContentChanged::dispatch($host);
            Canvas::claim([$canvas->getKey()], 'audit-owner', ['audit-output', 'new-output']);
            $this->assertSame([], $handler->calls);
        });
        $this->assertEquals([[$host, ['audit-output', 'new-output']]], $handler->calls);

        TgsContentChanged::dispatch(new HostDescriptor('missing', '1', 'content'));
        $this->assertCount(1, $handler->calls);
        TgsContentChanged::dispatch($host);
        $this->assertCount(2, $handler->calls);
    }

    #[Test]
    public function the_site_handler_receives_all_roots_of_a_shared_nested_block(): void
    {
        Storage::fake('public');
        Filament::setCurrentPanel(null);
        config(['tgs.named_canvas_cache_keys' => ['first' => ['first-output'], 'second' => ['second-output']]]);
        $first = Canvas::named('first')->hostDescriptor();
        $second = Canvas::named('second')->hostDescriptor();
        $persistence = app(CanvasPersistence::class);
        $column = $persistence->addBlock($first, 'canvas', $first->containerId, 'content', 'column', []);
        $block = $persistence->addBlock($first, 'block', $column->id, 'content', 'content', ['content' => 'old']);
        $persistence->insertReference($second, 'canvas', $second->containerId, 'content', $column->id);
        $media = $block->addMedia(UploadedFile::fake()->image('photo.jpg'))->toMediaCollection();
        Panel::make()->id('site-cache')->plugin(TgsPlugin::make()->siteCache(RecordingSiteCacheInvalidator::class));
        $handler = new RecordingSiteCacheInvalidator;
        app()->instance(RecordingSiteCacheInvalidator::class, $handler);

        $persistence->editBlockData($first, $block->id, ['content' => 'new']);
        $this->assertEqualsCanonicalizing([[$first, ['first-output']], [$second, ['second-output']]], $handler->calls);

        $handler->calls = [];
        event(new ConversionHasBeenCompletedEvent($media->fresh(), Conversion::create('late')));
        $this->assertEqualsCanonicalizing(
            ['first-output', 'second-output'],
            array_values(array_unique(array_merge(...array_column($handler->calls, 1)))),
        );
    }

    #[Test]
    public function postgres_reader_can_refill_the_old_value_until_deletion_commits(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires two PostgreSQL connections.');
        }

        config(['database.connections.cache_reader' => config('database.connections.testing')]);
        $reader = DB::connection('cache_reader');
        try {
            [$canvas] = $this->createTarget('named');
            $rows = $reader->table($canvas->getTable())->where('id', $canvas->getKey());
            DB::transaction(function () use ($canvas, $rows): void {
                $canvas->delete();
                $this->assertNull($canvas->newQuery()->find($canvas->getKey()));
                $this->assertSame('old', Cache::get('audit-output'));
                $this->assertTrue($rows->exists());
                Cache::put('audit-output', 'refilled from the old row');
            });
            $this->assertFalse($rows->exists());
            $this->assertNull(Cache::get('audit-output'));

            [$canvas] = $this->createTarget('named');
            DB::beginTransaction();
            $canvas->delete();
            $this->assertTrue($reader->table($canvas->getTable())->where('id', $canvas->getKey())->exists());
            DB::rollBack();
            $this->assertNotNull($canvas->newQuery()->find($canvas->getKey()));
            $this->assertSame('old', Cache::get('audit-output'));
        } finally {
            DB::purge('cache_reader');
        }
    }

    #[Test]
    #[DataProvider('hostKinds')]
    public function postgres_reader_cannot_refill_after_standalone_deletion_invalidates_cache(string $kind): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires two PostgreSQL connections.');
        }

        config(['database.connections.cache_reader' => config('database.connections.testing')]);
        $reader = DB::connection('cache_reader');
        try {
            [$target] = $this->createTarget($kind, fakeInvalidation: false);
            $rows = $reader->table($target->getTable())->where($target->getKeyName(), $target->getKey());
            $deleted = false;
            $invalidated = false;

            $target::deleted(function () use ($rows, &$deleted): void {
                $this->assertTrue($rows->exists());
                Cache::put('audit-output', 'refilled before commit');
                $deleted = true;
            });
            Event::listen(TgsCacheInvalidated::class, function (TgsCacheInvalidated $event) use ($rows, &$invalidated): void {
                $this->assertSame('audit-output', $event->key);
                $this->assertFalse($rows->exists());
                $invalidated = true;
            });

            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue($target->delete());
            $this->assertTrue($deleted);
            $this->assertTrue($invalidated);
            $this->assertNull(Cache::get('audit-output'));
        } finally {
            DB::purge('cache_reader');
        }
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
