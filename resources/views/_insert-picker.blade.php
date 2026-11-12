{{-- §2b UX follow-up (2026-07-22): insert drawer content, native Filament ->slideOver() modal
(TgsCanvas::insertBlockAction()) — replaces the hand-rolled Alpine backdrop+panel this used to be
inline in tgs-canvas.blade.php. Pure presentation partial: no wrapping divs/backdrop/close button
here, Filament's own action-modal chrome already provides all of that (heading, backdrop, cancel). --}}
<div>
    {{-- Вкладки дровера (срез 2a-2): «Новый» — прежняя галерея типов блоков,
    «Из библиотеки» — переиспользуемые блоки (Task 2, getLibraryBlocksProperty()).
    Вкладка «По токену» сюда не входит — вне рамок этого среза.

    На самой странице библиотеки (isLibraryHost) вкладки «Из библиотеки» и «Коллекции»
    скрыты целиком: вставка библиотечного блока (или блоков коллекции) В САМУ библиотеку
    циклична и не имеет смысла — та же логика, что уже прячет «Добавить в библиотеку» из
    кебаб-меню узла на этом же канвасе (см. addToLibraryAction()). --}}
    <x-filament::tabs class="mb-4.5 mx-0! text-xs" label="{{ __('tgs::filament.tgs.canvas.add_block') }}">
        <x-filament::tabs.item
            :active="$pickerTab === 'new'"
            wire:click="$set('pickerTab', 'new')"
        >
            {{ __('tgs::filament.tgs.canvas.library.tab_new') }}
        </x-filament::tabs.item>
        @unless ($isLibraryHost)
            <x-filament::tabs.item
                :active="$pickerTab === 'library'"
                wire:click="$set('pickerTab', 'library')"
            >
                {{ __('tgs::filament.tgs.canvas.library.tab_library') }}
            </x-filament::tabs.item>
            <x-filament::tabs.item
                :active="$pickerTab === 'collections'"
                wire:click="$set('pickerTab', 'collections')"
            >
                {{ __('tgs::filament.tgs.canvas.collections.tab_collections') }}
            </x-filament::tabs.item>
            <x-filament::tabs.item
                :active="$pickerTab === 'token'"
                wire:click="$set('pickerTab', 'token')"
            >
                {{ __('tgs::filament.tgs.canvas.token.tab_token') }}
            </x-filament::tabs.item>
        @endunless
    </x-filament::tabs>

    @if ($pickerTab === 'new')
        @if ($pickerIsFiltered)
            <p class="pb-2 text-xs text-gray-400">Показаны только блоки, разрешённые для этого слота.</p>
        @endif

        <div>
            @foreach (collect($pickerBlocks)->groupBy('category') as $category => $items)
                <div class="mb-4">
                    <strong class="mb-2 block text-xs uppercase tracking-wide text-gray-400">@if ($items->first()['category_icon'])
                            <x-filament::icon :icon="$items->first()['category_icon']" class="inline h-4 w-4" />
                        @endif
                        {{ $items->first()['category_label'] ?? '—' }}</strong>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach ($items as $item)
                            <button
                                type="button"
                                wire:click="chooseBlock('{{ $item['type'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="chooseBlock"
                                class="flex min-h-18 flex-col items-center justify-center gap-1.5 rounded-md border border-gray-200 p-3 text-center hover:border-primary-400 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:hover:bg-gray-800"
                            >
                                <x-filament::icon :icon="$item['icon']" class="h-5 w-5 shrink-0 text-gray-400" />
                                <span class="text-xs leading-tight text-gray-700 dark:text-gray-200">{{ $item['label'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @elseif ($pickerTab === 'library' && !$isLibraryHost)
        {{-- Вставить как: тогл asCopy живёт в Alpine (не в Livewire) — переключение
        не должно триггерить сетевой round-trip, читается только в момент клика по
        карточке через интерполяцию в wire:click. --}}
        <div x-data="{ asCopy: false }">
            <div class="flex items-center gap-2 pb-2 text-xs text-gray-500 dark:text-gray-400">
                <span>{{ __('tgs::filament.tgs.canvas.library.insert_as_label') }}</span>
                <div class="inline-flex overflow-hidden rounded-md border border-gray-200 dark:border-gray-700">
                    <button
                        type="button"
                        x-on:click="asCopy = false"
                        :class="!asCopy ? 'bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-400' : 'text-gray-400'"
                        class="px-2 py-1"
                    >
                        {{ __('tgs::filament.tgs.canvas.library.insert_as_reference') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="asCopy = true"
                        :class="asCopy ? 'bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-400' : 'text-gray-400'"
                        class="px-2 py-1"
                    >
                        {{ __('tgs::filament.tgs.canvas.library.insert_as_copy') }}
                    </button>
                </div>
            </div>

            @if ($libraryBlocks === [])
                <div class="rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-700 dark:text-gray-600">
                    {{ __('tgs::filament.tgs.canvas.library.empty') }}
                </div>
            @else
                <div class="grid grid-cols-3 gap-2">
                    @foreach ($libraryBlocks as $lib)
                        @include('tgs::_library-block-card', ['lib' => $lib])
                    @endforeach
                </div>
            @endif
        </div>
    @elseif ($pickerTab === 'collections' && !$isLibraryHost)
        {{-- Task 4 (block collections): каждая карточка — сама коллекция, не блок; клик
        открывает модалку выбора верхнеуровневых блоков коллекции (insertFromCollectionAction). --}}
        @if ($collections === [])
            <div class="rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-700 dark:text-gray-600">
                {{ __('tgs::filament.tgs.canvas.collections.empty') }}
            </div>
        @else
            <div class="space-y-2">
                @foreach ($collections as $collection)
                    <button
                        type="button"
                        wire:click="mountAction('insertFromCollectionAction', { collection: {{ $collection['id'] }} })"
                        class="flex w-full flex-col items-start gap-0.5 rounded-md border border-gray-200 p-3 text-left hover:border-primary-400 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800"
                    >
                        <span class="text-xs font-semibold leading-tight text-gray-700 dark:text-gray-200">{{ $collection['name'] ?? __('tgs::filament.tgs.canvas.collections.unnamed') }}</span>
                        @if (!empty($collection['description']))
                            <span class="line-clamp-2 text-[11px] text-gray-400">{{ $collection['description'] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif
    @elseif ($pickerTab === 'token' && !$isLibraryHost)
        {{-- §Token-срез: no curated list to click, unlike the library/collections tabs — just
        a short explainer and a button that mounts insertFromTokenAction() (Task 3), a nested
        Filament Action whose OWN schema (TextInput + Radio) and validation render inside its
        own slide-over, layered on top of this one (same nesting shape as
        insertFromCollectionAction, see that action's docblock). --}}
        <p class="pb-2 text-xs text-gray-400">{{ __('tgs::filament.tgs.canvas.token.heading') }}</p>
        <button
            type="button"
            wire:click="mountAction('insertFromTokenAction')"
            class="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500"
        >
            {{ __('tgs::filament.tgs.canvas.token.insert_button') }}
        </button>
    @endif
</div>
