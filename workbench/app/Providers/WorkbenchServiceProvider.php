<?php

namespace Workbench\App\Providers;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config(['app.locale' => 'ru', 'auth.providers.users.model' => User::class]);
        config(['queue.default' => 'sync', 'media-library.queue_conversions_by_default' => false]);

    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'tgs-workbench');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'tgs-workbench');
        FilamentAsset::register([
            AlpineComponent::make('counter-field', __DIR__.'/../../resources/js/counter-field.js'),
        ], package: 'tgs-workbench');
        $this->publishes([__DIR__.'/../../public/build/assets/theme.css' => public_path('vendor/tgs/workbench.css')], 'tgs-workbench-assets');
        Gate::define('tgs.access', fn ($user): bool => true);
        Gate::define('tgs.manageLibrary', fn ($user): bool => true);
        Gate::define('tgs.manageLocks', fn ($user): bool => $user->email === 'admin@tgs.test');
        Gate::define('tgs.deleteEverywhere', fn ($user): bool => $user->email === 'admin@tgs.test');
    }
}
