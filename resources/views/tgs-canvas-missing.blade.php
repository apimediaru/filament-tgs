{{-- Hotfix-срез 1 (Q1б): канвас с этим id удалён — вместо 404 на всю страницу-владельца показываем
плашку без мутирующих контролов (guardWritable() всё равно отказывает), чтобы редактор мог открыть
страницу и удалить битый элемент. Тот же визуальный язык, что у пустого канваса в tgs-canvas.blade.php,
только в тоне предупреждения. --}}
<div>
    <div class="rounded-md border border-dashed border-amber-300 p-6 text-center text-sm dark:border-amber-800">
        <p class="font-medium text-amber-700 dark:text-amber-400">{{ __('tgs::filament.tgs.canvas.missing.heading') }}</p>
        <p class="mt-1 text-gray-500 dark:text-gray-400">{{ __('tgs::filament.tgs.canvas.missing.description') }}</p>
    </div>
</div>
