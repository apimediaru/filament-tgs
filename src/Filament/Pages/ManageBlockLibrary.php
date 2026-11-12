<?php

namespace ApiMedia\Tgs\Filament\Pages;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Filament\Clusters\Tgs;
use ApiMedia\Tgs\Support\TgsModels;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class ManageBlockLibrary extends Page
{
    protected static ?string $cluster = Tgs::class;

    protected static ?string $slug = 'block-library';

    // No own navigationIcon (2026-07-23): the TGS nav group icon (AdminPanelProvider's
    // getNavigationGroups()) is enough — children stay unadorned, unlike CMS where every
    // child still carries its own icon.
    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?int $navigationSort = 400;

    protected string $view = 'tgs::filament.pages.manage-block-library';

    public int $canvasId;

    /**
     * Библиотека блоков — рабочий инструмент менеджера контента при переиспользовании
     * ТГС-блоков, сидит в собственном разделе ТГС (те же права, что раньше давал CMS —
     * см. RolesAndPermissionsSeeder). Не гейтится дефолтным для SettingsPage Management,
     * иначе роль manager (у неё нет access_section_management) не видит библиотеку вовсе.
     */
    public static function canAccess(): bool
    {
        return Gate::allows('tgs.access')
            && Gate::allows('tgs.manageLibrary');
    }

    /**
     * Flat inside the Tgs cluster: the section label would render a redundant collapsible «ТГС»
     * sub-heading in the cluster's sub-navigation. Access control uses the TGS Gate abilities above.
     */
    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public function mount(): void
    {
        $this->canvasId = TgsModels::canvas()::named(TgsNamedCanvas::BlockLibrary)->id;
    }

    public static function getNavigationLabel(): string
    {
        return __('tgs::filament.settings.pages.block_library.label');
    }

    /**
     * Count of root-level library blocks (the same "library block" definition
     * TgsCanvas::getLibraryBlocksProperty() uses — top-level placements in the library host's
     * own tree). Cached per-minute like UserResource::getNavigationBadge(), since this renders
     * on every panel page load regardless of whether the library page itself is visited.
     */
    public static function getNavigationBadge(): ?string
    {
        return Cache::remember(
            'filament:page:block-library:root-count',
            now()->addMinute(),
            static function (): string {
                $host = TgsModels::canvas()::named(TgsNamedCanvas::BlockLibrary)->hostDescriptor();

                return (string) TgsModels::placement()::query()
                    ->where('container_type', $host->containerType)
                    ->where('container_id', $host->containerId)
                    ->where('slot', $host->slot)
                    ->count();
            },
        );
    }

    public function getTitle(): Htmlable|string
    {
        return static::getNavigationLabel();
    }

    public function getHeading(): string|Htmlable
    {
        return static::getNavigationLabel();
    }
}
