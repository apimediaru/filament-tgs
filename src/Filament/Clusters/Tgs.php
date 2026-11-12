<?php

namespace ApiMedia\Tgs\Filament\Clusters;

use Filament\Clusters\Cluster;

use function __;
use function config;

/** Standalone TGS tools; pages authorize access with Gates and the site sets navigation grouping. */
class Tgs extends Cluster
{
    protected static ?string $slug = 'tgs';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    public static function getNavigationLabel(): string
    {
        return 'ТГС';
    }

    public static function getNavigationGroup(): ?string
    {
        $group = config('tgs.navigation_group');

        return $group !== null ? __($group) : null;
    }

    /**
     * Заголовок на страницах кластера (крошки/шапка) — полное название, не аббревиатура: в
     * пункте меню «ТГС» достаточно (короткая, узнаваемая метка рядом со Страницами/Статьями),
     * но на самих страницах кластера сокращение неинформативно для того, кто видит его не в
     * контексте меню. `getNavigationLabel()` выше умышленно остаётся «ТГС» — это разные места.
     */
    public static function getClusterBreadcrumb(): ?string
    {
        return 'Текстово-графическая страница';
    }
}
