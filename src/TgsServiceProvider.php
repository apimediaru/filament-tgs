<?php

namespace ApiMedia\Tgs;

use ApiMedia\Tgs\Events\TgsContentChanged;
use ApiMedia\Tgs\Listeners\CaptureMediaConversionData;
use ApiMedia\Tgs\Listeners\InvalidateBlockMediaCache;
use ApiMedia\Tgs\Listeners\InvalidateTgsHostCache;
use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Support\TgsAuthorization;
use ApiMedia\Tgs\Support\TgsBlockRegistry;
use ApiMedia\Tgs\Support\TgsConfiguration;
use ApiMedia\Tgs\Support\TgsHostRegistry;
use ApiMedia\Tgs\Support\TgsMediaConfiguration;
use ApiMedia\Tgs\Support\TgsModels;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use MediaEmbed\MediaEmbed;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

class TgsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('tgs')->hasConfigFile()->hasViews()->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(TgsConfiguration::class);
        $this->app->scoped(TgsBlockRegistry::class, function (): TgsBlockRegistry {
            $registry = new TgsBlockRegistry;
            foreach (app(TgsConfiguration::class)->plugins as $plugin) {
                $registry->blocks($plugin->blockClasses)->withoutBlocks($plugin->hiddenBlocks)
                    ->categories($plugin->categoryDescriptors)->withoutCategories($plugin->hiddenCategories);
            }

            return $registry;
        });
        $this->app->scoped(TgsHostRegistry::class, function (): TgsHostRegistry {
            $registry = (new TgsHostRegistry)->hosts([TgsModels::collection()], reusable: false, staging: true);
            foreach (app(TgsConfiguration::class)->plugins as $plugin) {
                foreach ($plugin->hostRegistrations as [$hosts, $reusable, $staging]) {
                    $registry->hosts($hosts, $reusable, $staging);
                }
            }

            return $registry;
        });
        $this->app->scoped(TgsMediaConfiguration::class, function (): TgsMediaConfiguration {
            $configuration = new TgsMediaConfiguration;
            foreach (app(TgsConfiguration::class)->plugins as $plugin) {
                if ($plugin->conversions !== null) {
                    $configuration->mediaConversions($plugin->conversions);
                }
            }

            return $configuration;
        });
        $this->app->bindIf(MediaEmbed::class, fn () => new MediaEmbed([], __DIR__.'/../resources/data/media-embed-vendors.php'));
    }

    public function packageBooted(): void
    {
        TgsAuthorization::registerDefaults();
        // Panel configuration is needed by CLI/API too, before a request selects its panel.
        Filament::getPanels();
        Livewire::addNamespace('tgs', classNamespace: Str::beforeLast(TgsCanvas::class, '\\'));
        Event::listen(TgsContentChanged::class, InvalidateTgsHostCache::class);
        Event::listen(ConversionHasBeenCompletedEvent::class, CaptureMediaConversionData::class);
        Event::listen(MediaHasBeenAddedEvent::class, CaptureMediaConversionData::class);
        Event::listen(ConversionHasBeenCompletedEvent::class, InvalidateBlockMediaCache::class);
        Event::listen(MediaHasBeenAddedEvent::class, InvalidateBlockMediaCache::class);
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\CheckTgsBlocksCommand::class,
                Console\Commands\GenerateTgsBlockUnionCommand::class,
                Console\Commands\GcBlockTrashCommand::class,
                Console\Commands\GcOrphanedEmbeddedCanvasesCommand::class,
                Console\Commands\SyncCanvasesCommand::class,
                Console\Commands\AdoptTgsSchemaCommand::class,
            ]);
        }
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/upgrades');
        if (config('tgs.install_schema', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
