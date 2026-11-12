<?php

namespace ApiMedia\Tgs\Filament\Concerns;

use ApiMedia\Tgs\Enums\TgsBlockLayout;
use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Support\TgsCategory;

/**
 * Декларативные возможности типа блока для канваса (ADR-019, B1). Дефолты безопасны: блок без объявлений — «голый» (иконка+название), без
 * слотов, без превью-полей, дети не ограничены (пустой allowedChildren = `*`).
 */
trait DeclaresBlockContract
{
    public function blockCategory(): ?TgsCategory
    {
        return null;
    }

    /** @return list<string> имена слотов-дропзон контейнера */
    public function blockSlots(): array
    {
        return [];
    }

    /**
     * Пустой список = нет ограничения (`*`). Форма:
     *  - list<string> — общий список для всех слотов блока (блочный дефолт);
     *  - array<string, list<string>> — по слоту (ключ = имя слота), с фолбэком на ключ '*'.
     *
     * @return array<int|string, mixed>
     */
    public function blockAllowedChildren(): array
    {
        return [];
    }

    /** @return list<string> имена скалярных полей data, показываемых в шапке блока */
    public function blockPreviewFields(): array
    {
        return [];
    }

    /**
     * Человекочитаемое превью из полных данных блока (backlog #23, лёгкая версия). null = нет.
     * `$block` — сама модель, а не только `data`: медиа-блоки (Picture/Gallery) не могут
     * определить наличие файла из `data` (upload-поле туда не дегидрируется), им нужен доступ
     * к реальной медиа-коллекции блока.
     */
    public function blockPreview(array $data, Block $block): ?string
    {
        return null;
    }

    public function blockLayout(): TgsBlockLayout
    {
        return TgsBlockLayout::Stack;
    }

    public function isReusableBlock(): bool
    {
        return false;
    }

    public function isSingletonBlock(): bool
    {
        return false;
    }

    /**
     * Блок существует только как элемент слота другого блока (например, tab_item внутри
     * tabs.items) — не самостоятельная запись read-side BlockRegistry, разворачивается
     * inline контейнером-родителем. Исключается из guard-теста синхронности
     * ContentBuilderFactory::blockClasses() ↔ BlockRegistry::map().
     */
    public function isChildOnlyBlock(): bool
    {
        return false;
    }

    /** @return string|null Blade-view кастомного рендера шапки (tier-3), null = дефолтный рендер */
    public function blockRenderer(): ?string
    {
        return null;
    }

    /** Метка кнопки, когда этот блок — единственный вариант добавления в слот (null = generic). */
    public function blockAddLabel(): ?string
    {
        return null;
    }

    /**
     * Task (2026-07-23, optional fields in collection): имена полей (state path блока),
     * которые становятся необязательными (`required(false)`), когда блок редактируется в
     * контексте коллекции (см. isCollectionHost()) — коллекция часто служит заготовкой
     * страницы, где реальный контент ещё не нужен. Библиотеку блоков и обычные страницы это
     * не затрагивает — только коллекции.
     *
     * @return list<string>
     */
    public function blockOptionalFieldsInCollection(): array
    {
        return [];
    }
}
