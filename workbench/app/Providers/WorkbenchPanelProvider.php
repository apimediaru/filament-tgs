<?php

namespace Workbench\App\Providers;

use ApiMedia\Tgs\TgsPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Workbench\App\Filament\Pages\ShowJson;
use Workbench\App\Filament\Resources\TgsDemoResource;
use Workbench\App\Models\TgsDemo;

class WorkbenchPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('workbench')->path('admin')->login()
            ->resources([TgsDemoResource::class])
            ->pages([ShowJson::class])
            ->theme(asset('vendor/tgs/workbench.css'))
            ->plugin(TgsPlugin::make()->hosts([TgsDemo::class], reusable: false))
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class])
            ->authMiddleware([Authenticate::class]);
    }
}
