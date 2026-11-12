{{--
    Ховер-«+» между соседними узлами (plan F, Gutenberg/Notion pattern): зовёт openPicker
    с явной позицией вставки. Намеренно БЕЗ x-sortable-item — Filament таргетит DnD на
    '[x-sortable-item]' (vendor/filament/support/resources/js/sortable.js), так что этот
    статичный узел не участвует в drag и не попадает в toArray() при reorder.

    Обёртка — h-0 без margin (не -my-N): у неё нет собственного бокса, поэтому расстояние
    между соседними узлами (их mb-2) не меняется и никто ни на кого не наезжает. Линия/кнопка
    визуально приподняты через transform (-translate-y-1, чисто рисование, на поток не влияет)
    ровно на половину mb-2 узла — так «+» оказывается по центру промежутка между блоками,
    а не прижатым к нижнему соседу. Невидимая полоса-хитбокс (-top-2/-bottom-2) расширяет
    зону наведения вокруг этой линии, чтобы ловить hover не только на самой кнопке.
--}}
@php
    $gapClick = $parentId === null
        ? "openPicker(null, null, {$position})"
        : "openPicker('{$parentId}', '{$slot}', {$position})";
@endphp
<div class="group/gap relative h-0 -translate-y-1">
    <div class="absolute inset-x-0 -top-2 -bottom-2"></div>
    <div class="pointer-events-none absolute inset-x-0 top-0 h-px -translate-y-1/2 bg-primary-400 opacity-0 transition group-hover/gap:opacity-100"></div>
    <button
        type="button"
        wire:click="{{ $gapClick }}"
        wire:loading.attr="disabled"
        wire:target="openPicker"
        class="absolute left-1/2 top-0 z-10 -translate-x-1/2 -translate-y-1/2 whitespace-nowrap rounded bg-primary-50 px-1.5 py-0.5 text-[11px] font-medium leading-none text-primary-600 opacity-0 shadow-sm transition hover:bg-primary-100 group-hover/gap:opacity-100 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-primary-950 dark:text-primary-400 dark:hover:bg-primary-900"
        title="Вставить блок здесь"
    >
        +
    </button>
</div>
