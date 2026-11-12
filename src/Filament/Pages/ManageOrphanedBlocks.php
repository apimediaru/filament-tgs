<?php

namespace ApiMedia\Tgs\Filament\Pages;

use ApiMedia\Tgs\Filament\Clusters\Tgs;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Support\TgsModels;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Осиротевшие блоки (owner_container_type='orphan', \ApiMedia\Tgs\Support\TgsModels::block()::unclaimedOwner()) — блоки, чей
 * владевший хост снял своё размещение, но которые всё ещё используются в других местах.
 * Остаются живыми/видимыми там, где у них есть BlockPlacement, но администратору сейчас
 * физически негде их найти/отредактировать/удалить — отсюда эта отдельная страница.
 */
class ManageOrphanedBlocks extends Page
{
    protected static ?string $cluster = Tgs::class;

    protected static ?string $slug = 'orphaned-blocks';

    // Флат внутри кластера Tgs — тот же приём, что у ManageBlockLibrary: раздел «ТГС» уже
    // несёт свою навигационную иконку, дочерние страницы её не повторяют.
    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?int $navigationSort = 410;

    protected string $view = 'tgs::filament.pages.manage-orphaned-blocks';

    public static function canAccess(): bool
    {
        return Gate::allows('tgs.access')
            && Gate::allows('tgs.manageLibrary');
    }

    /**
     * Флат внутри кластера Tgs: секционная подпись дала бы избыточный collapsible-заголовок
     * «ТГС» — доступ определяется Gate abilities выше.
     */
    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return __('tgs::filament.settings.pages.orphaned_blocks.label');
    }

    /**
     * Кэш на минуту — тот же паттерн, что и ManageBlockLibrary::getNavigationBadge()
     * (рендерится на каждой странице панели, а не только при визите этой страницы).
     */
    public static function getNavigationBadge(): ?string
    {
        return Cache::remember(
            'filament:page:orphaned-blocks:count',
            now()->addMinute(),
            static fn (): string => (string) TgsModels::block()::query()->where('owner_container_type', 'orphan')->alive()->committed()->count(),
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
