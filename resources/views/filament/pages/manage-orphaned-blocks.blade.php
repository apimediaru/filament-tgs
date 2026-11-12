<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
        <p class="font-medium text-gray-900 dark:text-gray-100">Что такое осиротевшие блоки</p>
        <p class="mt-1">
            Блок становится «осиротевшим», когда его владевший хост (страница, коллекция и
            т.п.) снимает своё размещение, а блок при этом всё ещё используется в других
            местах — он остаётся живым и видимым там, где ещё размещён, но никто за него
            больше не отвечает. Здесь можно отредактировать такой блок, перевести его в
            библиотеку (он получит владельца, ничего не удаляя из текущих мест использования)
            или удалить его совсем — везде.
        </p>
    </div>

    <x-filament::section>
        <livewire:tgs::orphaned-blocks-table />
    </x-filament::section>
</x-filament-panels::page>
