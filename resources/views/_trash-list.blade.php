{{-- Этап 4, Слайс D: содержимое TgsTrashDrawer::trashAction()'s ->slideOver(). Чистая
презентационная партиалка — без обёрточных div/backdrop/transition, всё это уже даёт Filament'ов
action-modal chrome (заголовок, backdrop, кнопка закрытия). --}}
<div class="space-y-2">
    @forelse ($entries as $entry)
        <div class="flex items-center justify-between gap-3 rounded-md border border-gray-200 px-3 py-2 dark:border-gray-700" data-testid="trash-entry" wire:key="trash-entry-{{ $entry->id }}">
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-1.5">
                    <x-filament::icon :icon="$entry->icon" class="h-4 w-4 shrink-0 self-center text-gray-500 dark:text-gray-400" />
                    <span class="shrink-0 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $entry->label }}</span>
                    @if ($entry->preview)
                        <span class="min-w-0 flex-1 truncate text-xs text-gray-500 dark:text-gray-400">&middot; {{ $entry->preview }}</span>
                    @endif
                </div>
                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $entry->authorLabel }}</span> &middot; {{ $entry->createdAt->diffForHumans() }} &middot; {{ $entry->locationLabel }}
                    @if ($entry->nestedCount > 0)
                        &middot; {{ trans_choice('tgs::filament.tgs.canvas.nested_count', $entry->nestedCount, ['count' => $entry->nestedCount]) }}
                    @endif
                </div>
            </div>

            @unless ($readonly)
                <button
                    type="button"
                    data-testid="restore-block"
                    wire:click="restore('{{ $entry->id }}')"
                    class="shrink-0 rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    {{ __('tgs::filament.tgs.canvas.trash.restore') }}
                </button>
            @endunless
        </div>
    @empty
        <div class="rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-700 dark:text-gray-600">
            {{ __('tgs::filament.tgs.canvas.trash.empty') }}
        </div>
    @endforelse
</div>
