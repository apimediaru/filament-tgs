{{-- Строка «Добавить блок» + индикатор автосейва. Переиспользуется в шапке канваса и в
условном футере (canvas-usability-polish, Task C), чтобы футер выглядел так же, как шапка.
Ожидает $autosaveKey (уникальный wire:key для индикатора на этом рендере) и $showCollapseToggle
(показать ли кнопку свёртывания всех блоков; true в шапке, false в футере для избежания
синхронизации двух независимых Alpine-скоупов). --}}
<div class="flex items-center justify-between gap-3">
    <div class="flex items-center gap-2">
        <button
            type="button"
            wire:click="openPicker"
            wire:loading.attr="disabled"
            wire:target="openPicker"
            class="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-50"
        >
            + Добавить блок
        </button>

        {{-- Свернуть всё / Развернуть всё (2026-07-23 UX follow-up): чисто клиентская
        Alpine-фича, без обращения к Livewire — каждый узел (_node.blade.php) уже держит
        своё собственное `x-data="{ collapsed: false }"`, эта кнопка просто широковещательно
        рассылает window-событие `tgs-collapse-all`, на которое подписан КАЖДЫЙ узел (и
        листовые тоже — у них collapsed просто никогда не читается, подписка безобидна). Тот
        же паттерн, что уже используется для одиночного тоггла свёртывания одного узла —
        никакого нового server-side состояния. --}}
        @if ($showCollapseToggle)
            <div x-data="{ allCollapsed: false }">
                <button
                    type="button"
                    x-on:click="allCollapsed = !allCollapsed; $dispatch('tgs-collapse-all', { collapsed: allCollapsed })"
                    class="rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-500 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
                >
                    <span x-show="!allCollapsed">Свернуть всё</span>
                    <span x-show="allCollapsed" x-cloak>Развернуть всё</span>
                </button>
            </div>

            {{-- Этап 4, Слайс D: кнопка «Корзина (N)» рядом со «Свернуть всё» — ВСЕГДА видна
            (иначе пользователю негде узнать, что корзина вообще существует, пока в ней
            пусто — отступление от буквального Требования 6.4 «не отображается при нуле» по
            прямой просьбе пользователя), но задизейблена и некликабельна при нуле. Диспатчит
            открытие ОТДЕЛЬНОГО Livewire-компонента TgsTrashDrawer (см. его class docblock),
            тем же паттерном, что и «open-block-history» из _node.blade.php. --}}
            <button
                type="button"
                data-testid="trash-button"
                @disabled($this->trashCount === 0)
                wire:click="$dispatch('open-canvas-trash', { canvasId: {{ $canvasId }} })"
                class="rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-500 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
            >
                {{ __('tgs::filament.tgs.canvas.trash.counter', ['count' => $this->trashCount]) }}
            </button>
        @endif
    </div>

    <div wire:key="{{ $autosaveKey }}" class="text-xs text-gray-400 dark:text-gray-500">
        @if ($savedAt !== null)
            <span data-testid="autosave-indicator">Сохранено &middot; {{ $savedAt }}</span>
        @else
            <span data-testid="autosave-indicator">Изменения сохраняются автоматически</span>
        @endif
    </div>
</div>
