<?php

namespace ApiMedia\Tgs\Filament\Resources\CollectionResource\Pages;

use ApiMedia\Tgs\Filament\Resources\CollectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

class ListCollections extends ListRecords
{
    protected static string $resource = CollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * 2026-07-23: short explainer above the table — what a collection actually is and how
     * inserting from one differs from the block library (copy-only, no shared-reference
     * option, unlike "Из библиотеки"'s copy/reference toggle). Overrides the parent's
     * content() (base ListRecords::content()) only to prepend this one component; everything
     * else (tabs, table, render hooks) is copied verbatim from the base implementation.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Html::make(new HtmlString(<<<'HTML'
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
                    <p class="font-medium text-gray-900 dark:text-gray-100">Как работает коллекция блоков</p>
                    <p class="mt-1">
                        Коллекция — именованный набор блоков: откройте её и наполните блоками, как
                        обычную страницу. Затем на любой странице нажмите «Добавить блок» → вкладка
                        «Коллекции», выберите эту коллекцию и отметьте, какие из её блоков вставить —
                        они скопируются на страницу отдельными, независимыми копиями. Это не то же
                        самое, что ссылка в библиотеке блоков: изменения в коллекции не затронут уже
                        вставленные копии, и наоборот.
                    </p>
                </div>
            HTML)),
            $this->getTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }
}
