{{-- §13: history drawer, its OWN Livewire component (see TgsBlockHistoryDrawer's class docblock
for why this must never be merged back into tgs-canvas.blade.php's own template) — its markup
never enters TgsCanvas's morph pass. All UI (slide-over list, full-diff modal) is native Filament
Actions (historyAction/viewDiffAction) — this root only hosts their modals, no custom Alpine. --}}
<div>
    <x-filament-actions::modals />
</div>
