<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Filament\Pages\ManageOrphanedBlocks;
use ApiMedia\Tgs\Tests\TgsUiTestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class PublishedViewsTest extends TgsUiTestCase
{
    use RefreshDatabase;

    private string $temporaryBase;

    private string $frameworkMigrations;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->frameworkMigrations = \Orchestra\Testbench\default_migration_path();
        $this->temporaryBase = sys_get_temp_dir().'/tgs-views-'.bin2hex(random_bytes(6));
        foreach (['bootstrap/cache', 'storage/framework/views', 'storage/logs', 'resources/views/vendor/tgs'] as $directory) {
            mkdir($this->temporaryBase.'/'.$directory, 0777, true);
        }
        $app->getNamespace();
        $app->setBasePath($this->temporaryBase);
        $app->useStoragePath($this->temporaryBase.'/storage');
        $app['config']->set('view.paths', [$this->temporaryBase.'/resources/views']);
        $app['config']->set('view.compiled', $this->temporaryBase.'/storage/framework/views');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom($this->frameworkMigrations);
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/workbench/database/migrations');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem)->deleteDirectory($this->temporaryBase);
    }

    #[Test]
    public function published_page_and_nested_partial_render_after_cache_and_fall_back_when_removed(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'tgs-views'])->assertSuccessful();
        $local = resource_path('views/vendor/tgs');
        $finder = app('view')->getFinder();
        $files = File::allFiles($local);
        $this->assertCount(16, $files);
        foreach ($files as $file) {
            $name = 'tgs::'.str_replace('/', '.', substr($file->getRelativePathname(), 0, -10));
            $this->assertSame($file->getPathname(), $finder->find($name));
        }
        $page = $local.'/filament/pages/manage-orphaned-blocks.blade.php';
        $partial = $local.'/_orphaned-blocks-list.blade.php';
        File::put($page, str_replace('<x-filament-panels::page>', '<x-filament-panels::page><span>TGS_LOCAL_PAGE_MARKER</span>', File::get($page)));
        File::prepend($partial, '<span>TGS_LOCAL_PARTIAL_MARKER</span>');
        Livewire::test(ManageOrphanedBlocks::class)->assertSee('TGS_LOCAL_PAGE_MARKER')->assertSee('TGS_LOCAL_PARTIAL_MARKER');
        $this->artisan('view:cache')->assertSuccessful();
        Livewire::test(ManageOrphanedBlocks::class)->assertSee('TGS_LOCAL_PAGE_MARKER')->assertSee('TGS_LOCAL_PARTIAL_MARKER');
        File::delete([$page, $partial]);
        $finder->flush();
        $this->artisan('view:clear')->assertSuccessful();
        $this->assertStringNotContainsString($local, $finder->find('tgs::_orphaned-blocks-list'));
        Livewire::test(ManageOrphanedBlocks::class)->assertDontSee('TGS_LOCAL_PAGE_MARKER')
            ->assertDontSee('TGS_LOCAL_PARTIAL_MARKER')->assertSee('Что такое осиротевшие блоки');
    }
}
