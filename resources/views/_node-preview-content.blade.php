{{--
    Extracted from the node header (Stage 2, node header priority/compaction plan) so the same
    preview content can render in TWO places — inline next to the header on a wide container,
    full-width below the header on a narrow one (see the `@container` toggle in _node.blade.php)
    — without duplicating the branching logic itself. Expects `$line` and `$node` from the
    caller, exactly as computed in _node.blade.php's top `@php` block.
--}}
@if ($line !== null)
    &middot; {{ $line }}
@elseif ($node['childCount'] > 0)
    {{-- Task C (node header priority/compaction plan): the child count no longer renders
    here as text — it moved to a small number next to the collapse/expand chevron. This
    branch just stays empty instead of falling through to "Нет содержимого", which would be
    misleading (the container is NOT empty, it just has no preview text of its own). --}}
@elseif (count($node['slots']) === 0)
    {{-- Листовой блок (без слотов) без превью-строки: «нет содержимого» вводило бы в
    заблуждение (это не пустой контейнер) — показываем заполненность самих полей данных
    вместо этого. --}}
    &middot;
    <span class="italic text-gray-400 dark:text-gray-600">
        {{ $node['hasData'] ? __('tgs::filament.tgs.canvas.filled') : __('tgs::filament.tgs.canvas.not_filled') }}
    </span>
@else
    &middot; <span class="italic text-gray-400 dark:text-gray-600">{{ __('tgs::filament.tgs.canvas.empty') }}</span>
@endif
