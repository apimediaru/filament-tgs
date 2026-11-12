{{-- §13: version list content for TgsBlockHistoryDrawer::historyAction()'s slide-over. Pure
presentation partial — no wrapping divs/backdrop/transitions here, Filament's own action-modal
chrome (header, description, backdrop, close button) already provides all of that. --}}
<div class="space-y-2">
    @forelse ($versions as $version)
        <div class="flex items-center justify-between rounded-md border border-gray-200 px-3 py-2 dark:border-gray-700" data-testid="history-version">
            <div class="text-xs text-gray-500 dark:text-gray-400">
                <div>{{ $version->created_at->format('d.m.Y H:i:s') }} — {{ $version->authorLabel }}</div>
                <div class="mt-0.5 text-gray-700 dark:text-gray-300">{{ $version->summary }}</div>
            </div>
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    data-testid="view-diff"
                    wire:click="mountAction('viewDiffAction', { versionId: {{ $version->id }} })"
                    class="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    {{ __('tgs::filament.tgs.canvas.history.view_diff') }}
                </button>
                @php $lockedForUser = \ApiMedia\Tgs\Support\TgsBlock::isLockedForCurrentUser($historyBlockId); @endphp
                @unless ($readonly || $lockedForUser)
                    <button
                        type="button"
                        data-testid="revert-version"
                        wire:click="revertVersion({{ $version->id }})"
                        class="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        {{ __('tgs::filament.tgs.canvas.history.revert') }}
                    </button>
                @endunless
            </div>
        </div>
    @empty
        <div class="rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-700 dark:text-gray-600">
            {{ __('tgs::filament.tgs.canvas.history.empty') }}
        </div>
    @endforelse
</div>
