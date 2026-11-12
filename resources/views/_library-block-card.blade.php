{{--
    Карточка блока библиотеки в дровере «Из библиотеки» (extracted for readability/reuse —
    was inline in tgs-canvas.blade.php). Тип блока показывается ВСЕГДА жирным — он
    определяет, что вставится и как ведёт себя allowedChildren-фильтр; $lib['name']
    (опциональное пользовательское имя, Task 6 "blocks as entities") — только для того,
    чтобы различать несколько библиотечных блоков одного типа, второй строкой, приглушённо.

    $lib — элемент TgsCanvas::getLibraryBlocksProperty(): {id, type, label, name, preview}.
--}}
<button
    type="button"
    wire:click="insertFromLibrary('{{ $lib['id'] }}', asCopy)"
    class="flex min-h-18 flex-col items-center justify-center gap-1.5 rounded-md border border-gray-200 p-3 text-center hover:border-primary-400 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800"
>
    <span class="w-full min-w-0 wrap-break-word text-xs font-semibold leading-tight text-gray-700 dark:text-gray-200">{{ $lib['label'] }}</span>
    @if (!empty($lib['name']))
        <span class="w-full min-w-0 wrap-break-word text-[11px] text-gray-400 dark:text-gray-500">{{ $lib['name'] }}</span>
    @endif
    @if ($lib['preview'] !== null)
        <span class="line-clamp-2 text-[11px] text-gray-400">{{ $lib['preview'] }}</span>
    @endif
</button>
