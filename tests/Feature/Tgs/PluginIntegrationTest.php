<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Services\AdoptTgsSchema;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\Contracts\InvalidatesCache;
use ApiMedia\Tgs\Support\TgsBlockRegistry;
use ApiMedia\Tgs\Support\TgsHostRegistry;
use ApiMedia\Tgs\Tests\TestCase;
use ApiMedia\Tgs\TgsPlugin;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\TgsDemo;
use Workbench\App\Models\User;

class PluginIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_access_does_not_grant_dangerous_default_abilities(): void
    {
        $this->assertFalse(Gate::allows('tgs.access'));
        $this->actingAs(User::create(['name' => 'Reader', 'email' => 'reader@example.test', 'password' => 'password']));
        $this->assertTrue(Gate::allows('tgs.access'));
        foreach (['tgs.manageLibrary', 'tgs.manageLocks', 'tgs.deleteEverywhere'] as $ability) {
            $this->assertFalse(Gate::allows($ability));
        }
    }

    #[Test]
    public function plugin_configuration_survives_fresh_scopes_without_leaking_request_registry_mutations(): void
    {
        TgsPlugin::make()->hosts([CachedDemo::class])->withoutBlocks(['picture'])->register(Panel::make()->id('external'));
        app()->forgetScopedInstances();
        $first = app(TgsBlockRegistry::class);
        $this->assertFalse($first->isAvailable('picture'));
        $this->assertNotNull($first->get('picture'));
        $this->assertSame(CachedDemo::class, app(TgsHostRegistry::class)->resolve('cached_demo'));
        $first->withoutBlocks(['content']);
        app()->forgetScopedInstances();
        $this->assertTrue(app(TgsBlockRegistry::class)->isAvailable('content'));
        $this->assertFalse(app(TgsBlockRegistry::class)->isAvailable('picture'));
    }

    #[Test]
    public function plugin_conversion_callback_is_used_by_real_blocks(): void
    {
        $calls = [];
        TgsPlugin::make()->mediaConversions(function (Block $block, $media) use (&$calls): void {
            $calls[] = $block->id;
            $conversion = $block->addMediaConversion('banner');
            $conversion->width(300);
            $conversion->nonQueued();
        })->register(Panel::make()->id('media'));
        app()->forgetScopedInstances();
        $block = Block::factory()->picture()->make();
        $block->registerAllMediaConversions();
        $this->assertSame([$block->id], $calls);
        $this->assertSame(['banner'], array_map(fn ($conversion) => $conversion->getName(), $block->mediaConversions));
    }

    #[Test]
    public function media_events_invalidate_host_cache_without_an_application_listener(): void
    {
        Storage::fake('public');
        TgsPlugin::make()->hosts([CachedDemo::class])->register(Panel::make()->id('cache'));
        app()->forgetScopedInstances();
        $hostModel = CachedDemo::create(['name' => 'Cache host']);
        $host = $hostModel->canvasDescriptor('content');
        $block = app(CanvasPersistence::class)->addBlock($host, $host->containerType, $host->containerId, $host->slot, 'picture', ['uuid' => 'field']);
        Cache::put('package-media-output', 'old');
        $block->addMedia(UploadedFile::fake()->image('photo.jpg'))->withCustomProperties(['uuid' => 'field'])->toMediaCollection(Block::getContentMediaCollectionName());
        $this->assertFalse(Cache::has('package-media-output'));
    }

    #[Test]
    public function adopting_existing_schema_is_validated_and_idempotent(): void
    {
        DB::table('migrations')->where('migration', AdoptTgsSchema::MIGRATION)->delete();
        $this->artisan('tgs:adopt-existing')->assertSuccessful();
        $this->artisan('tgs:adopt-existing')->assertSuccessful();
        $this->assertSame(1, DB::table('migrations')->where('migration', AdoptTgsSchema::MIGRATION)->count());
    }

    #[Test]
    public function adoption_refuses_an_incomplete_schema_without_recording_a_baseline(): void
    {
        DB::table('migrations')->where('migration', AdoptTgsSchema::MIGRATION)->delete();
        Schema::table('tgs_blocks', fn ($table) => $table->dropColumn('committed_at'));
        $this->artisan('tgs:adopt-existing')->assertFailed();
        $this->assertSame(0, DB::table('migrations')->where('migration', AdoptTgsSchema::MIGRATION)->count());
    }
}

class CachedDemo extends TgsDemo implements InvalidatesCache
{
    public static function tgsHostKey(): string
    {
        return 'cached_demo';
    }

    public function cacheKeysToInvalidate(): array
    {
        return ['package-media-output'];
    }
}
