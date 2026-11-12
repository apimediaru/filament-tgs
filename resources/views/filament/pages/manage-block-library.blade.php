<x-filament-panels::page>
    {{-- 2026-07-23: short explainer above the canvas — mirrors the one on the Collections
    list page (resources/views/... via ListCollections::content()), same reasoning: this is
    the first thing a manager sees before they understand what "library" even means here. --}}
    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
        <p class="font-medium text-gray-900 dark:text-gray-100">Как работает библиотека блоков</p>
        <p class="mt-1">
            Библиотека — общий набор готовых блоков, которые можно использовать на любой
            странице. Добавляйте и редактируйте блоки прямо здесь. Затем на любой странице
            нажмите «Добавить блок» → вкладка «Из библиотеки», выберите нужный блок и вставьте
            его — копией (отдельная версия, её можно менять независимо) или ссылкой (тот же
            блок: если поправить его здесь, изменения сразу появятся везде, где он вставлен).
            Число под каждым блоком показывает, на скольких страницах он уже используется как
            ссылка.
        </p>
    </div>

    <x-filament::section>
        <livewire:tgs::tgs-canvas :canvas-id="$canvasId" />
    </x-filament::section>
</x-filament-panels::page>
