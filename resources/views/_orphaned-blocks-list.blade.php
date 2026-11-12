{{-- Список строк «Осиротевшие блоки» — в стиле _trash-list.blade.php (border/rounded-md/
dark-mode), но три кнопки-действия вместо одной «Вернуть»: Редактировать / В библиотеку /
Удалить везде. --}}
<div class="space-y-2">
    @forelse ($entries as $entry)
        <div class="flex items-center justify-between gap-3 rounded-md border border-gray-200 px-3 py-2 dark:border-gray-700" data-testid="orphaned-block-entry" wire:key="orphaned-block-{{ $entry->id }}">
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-1.5">
                    <x-filament::icon :icon="$entry->icon" class="h-4 w-4 shrink-0 self-center text-gray-500 dark:text-gray-400" />
                    <span class="shrink-0 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $entry->label }}</span>
                    @if ($entry->preview)
                        <span class="min-w-0 flex-1 truncate text-xs text-gray-500 dark:text-gray-400">&middot; {{ $entry->preview }}</span>
                    @endif
                </div>
                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('tgs::filament.tgs.orphaned_blocks.used_in') }}: {{ $entry->usageLabel }}
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <button
                    type="button"
                    data-testid="edit-orphaned-block"
                    wire:click="mountAction('editBlockAction', {{ \Illuminate\Support\Js::from(['block' => $entry->id, 'type' => $entry->type, 'label' => $entry->label]) }})"
                    class="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    {{ __('tgs::filament.tgs.orphaned_blocks.actions.edit') }}
                </button>
                <button
                    type="button"
                    data-testid="adopt-orphaned-block"
                    wire:click="mountAction('adoptIntoLibraryAction', {{ \Illuminate\Support\Js::from(['block' => $entry->id]) }})"
                    class="rounded bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700 hover:bg-primary-100 dark:bg-primary-900/40 dark:text-primary-300 dark:hover:bg-primary-900/60"
                >
                    {{ __('tgs::filament.tgs.orphaned_blocks.actions.adopt') }}
                </button>
                <button
                    type="button"
                    data-testid="delete-orphaned-block"
                    wire:click="mountAction('deleteBlockAction', {{ \Illuminate\Support\Js::from(['block' => $entry->id]) }})"
                    class="rounded bg-danger-50 px-2 py-1 text-xs font-medium text-danger-700 hover:bg-danger-100 dark:bg-danger-900/40 dark:text-danger-300 dark:hover:bg-danger-900/60"
                >
                    {{ __('tgs::filament.tgs.orphaned_blocks.actions.delete') }}
                </button>
            </div>
        </div>
    @empty
        <div class="rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-700 dark:text-gray-600">
            {{ __('tgs::filament.tgs.orphaned_blocks.empty') }}
        </div>
    @endforelse
</div>
