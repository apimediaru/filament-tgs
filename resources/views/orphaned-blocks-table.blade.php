{{-- Осиротевшие блоки: обычная полностраничная таблица (не slideOver, в отличие от корзины
tgs-trash-drawer.blade.php) — весь список рендерится прямо в этом корне, модалки actions'ов
хостятся тем же <x-filament-actions::modals /> паттерном. --}}
<div>
    @include('tgs::_orphaned-blocks-list', ['entries' => $entries])

    <x-filament-actions::modals />
</div>
