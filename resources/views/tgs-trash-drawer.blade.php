{{-- Этап 4, Слайс D: корзина канваса, СВОЙ Livewire-компонент (см. TgsTrashDrawer's class
docblock, та же причина, что и у TgsBlockHistoryDrawer — не сливать обратно в
tgs-canvas.blade.php). Вся разметка (slide-over список, кнопка «Вернуть») — native Filament
Action (trashAction) — этот корень только хостит его модал. --}}
<div>
    <x-filament-actions::modals />
</div>
