{{--
    Рекурсивный узел дерева (Task 9, plan B2): иконка типа + название + одна строка превью
    (пустое → бледный плейсхолдер), плоский тулбар (корень — всегда виден, вложенные узлы —
    по наведению), именованные слоты-дропзоны с пунктирным "пустым" состоянием, отступ +
    направляющая линия по вложенности.

    Реордер внутри слота — x-sortable (Task 7): x-sortable-item на узле,
    x-sortable-handle — минимальная ручка перетаскивания (см. vendor/filament/support/resources/js/sortable.js —
    SortableJS требует элемент, совпадающий с `handle`, иначе drag не стартует).

    $path — путь узла в дереве (индексы корня + имена слотов + индексы внутри слота),
    а не просто id блока. CanvasTreeReader намеренно допускает повтор одного и того же
    id блока в двух независимых слотах (переиспользование блоков, plan §10), поэтому
    wire:key обязан быть path-qualified, иначе Livewire/Alpine схлопнут diff по двум
    узлам с одинаковым ключом.

    $depth — 0 для корневых узлов (тулбар всегда виден), > 0 для вложенных (тулбар
    показывается по наведению через group-hover).
--}}
@php
    $parentBlockId ??= null;
    $parentSlot ??= null;
    $parentIsForeign ??= false;
    $parentPlacementsCount ??= null;
    $parentRowLayoutDepth ??= 0;
    $meta = $this->blockMeta($node['type']);
    $isForeign = $this->isForeignBlock($node['id']);
    $detachContainer = $parentBlockId === null
        ? $this->rootContainer()
        : ['containerType' => 'block', 'containerId' => $parentBlockId, 'slot' => $parentSlot];
    $previewText = collect($node['preview'])
        ->filter(fn ($value) => $value !== null && $value !== '')
        ->map(fn ($value) => is_scalar($value) ? (string) $value : '')
        ->filter()
        ->implode(' · ');
    $line = $node['previewLine']
        ?? ($previewText !== '' ? $previewText : null);
    $toolbarVisibility = $depth === 0 ? '' : 'opacity-60 group-hover/node:opacity-100 transition-opacity';
    $lock = $this->lockState($node['id']);
    $canManageLocks = $this->canManageLocks();
    $isLibraryHost = $this->isLibraryHost();
@endphp
<div
    wire:key="node-{{ $path }}-{{ $node['id'] }}"
    @unless ($readonly)
        x-sortable-item="{{ $node['id'] }}"
    @endunless
    x-data="{ collapsed: false }"
    x-on:tgs-collapse-all.window="collapsed = $event.detail.collapsed"
    class="group/node mb-2 rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
>
    {{-- Stage 2 (node header priority/compaction plan), revised again after live review: the
    header (drag-handle, icon, label, name-badge, usage/lock indicators, toolbar) ALWAYS stays
    one row now — it never reflows into a vertical stack, no matter how narrow. Below
    `@container`'s 360px breakpoint (declared on the slot wrapper further down — see
    `@container` there) only the preview text moves: the inline `flex-1 truncate` version
    hides, and a full-width version below the header takes over, wrapping onto two lines
    (`line-clamp-2`) instead of a single truncated line — see `_node-preview-content.blade.php`,
    included from both spots so the branching logic isn't duplicated. Mechanism 1 (forced
    single-column on nested double_column, above) already removes the exponential 50/50 width
    collapse that used to make the header THIS narrow in typical trees; this is for whatever
    narrowness is still left over — a deep stack, a long block name, a small viewport. --}}
    <div class="flex items-center gap-2 px-3 py-2">
        <div class="min-w-0 flex-1">
            {{-- `items-center`, not `items-baseline`: this row's two children are the
            identity sub-`<div>` (icon+label+name, itself a nested flex container) and the
            preview `<span>` — a nested flex element's baseline is the baseline of ITS OWN
            first flex line, which renders inconsistently next to a plain inline span and
            visually reads as misaligned text ("Содержимое" vs "· preview" sitting off from
            each other). Centering both boxes by their line-height avoids that entirely. --}}
            <div class="flex items-center gap-1.5">
                <div class="flex items-center gap-1.5">
                    {{-- reorderChild() gates on parent ownership
                    (blockBelongsToHost($parentBlockId)) — dragging among siblings inside a
                    foreign parent's slot is a no-op, so the handle is suppressed via
                    $parentIsForeign. Reordering THIS node itself among ITS OWN siblings stays
                    allowed even when $isForeign (moving a reference is fine — see
                    x-sortable-item above, always present). Read-only (Task 3) drops the handle
                    unconditionally — DnD is off at the source, not just refused server-side. --}}
                    @unless ($lock['lockedForUser'] || $parentIsForeign || $readonly)
                        {{-- bi-grip-vertical inlined and trimmed to its top 3 rows (was 5 rows/10
                        dots — too dense at this size, and visually competed with the node menu's
                        own 3-dot ellipsis below): 3x2 = 6 dots, the common "grip handle" pattern.
                        Row spacing widened from the original 3 units to 4 (y=4/8/12, still
                        centered in the same 16x16 viewBox the rest of the toolbar's icons use)
                        for a bit more breathing room between dots at this size. --}}
                        <span x-sortable-handle class="cursor-grab text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" title="Перетащить">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="h-4 w-4"><path d="M7 4a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0M7 8a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0M7 12a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/></svg>
                        </span>
                    @endunless
                    <x-filament::icon :icon="$meta['icon']" class="h-4 w-4 shrink-0 self-center text-gray-500 dark:text-gray-400" />
                    <span class="shrink-0 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $meta['label'] }}</span>
                    @if (!empty($node['name']))
                        {{-- Task D (node header priority/compaction plan), revised after live
                        review: a full-width name badge (flex-1, replacing the preview) looked
                        oversized and out of place next to every other row's compact label — the
                        name is metadata for the author, not the row's primary content. It's now a
                        small shrink-0 icon badge instead, matching the shape of the other chips in
                        this row; the name text itself lives in the tooltip, and the preview line
                        keeps rendering inline below exactly like on an unnamed block. --}}
                        <span
                            data-testid="node-name"
                            x-tooltip="{ content: @js($node['name']), theme: 'dark' }"
                            class="inline-flex shrink-0 items-center rounded bg-sky-100 p-1 text-sky-700 dark:bg-sky-950 dark:text-sky-400"
                        >
                            <x-filament::icon icon="heroicon-o-tag" class="h-3 w-3 shrink-0" />
                        </span>
                    @endif
                </div>
                {{-- One-line node header (2026-07-23 UX follow-up, still the default shape):
                title + preview used to be two stacked lines — merged into a single row so a
                node takes less vertical space. The preview span is the only `flex-1`/`truncate`
                piece, so it's the one that eats remaining space and ellipses; label/name stay
                `shrink-0` so they're never cut. Hidden below the 360px `@container` breakpoint
                — the full-width block version further down (`data-testid="preview-block"`)
                takes over there instead of squeezing into this row. --}}
                <span class="min-w-0 flex-1 truncate text-xs text-gray-500 dark:text-gray-400 @max-[360px]:hidden">
                    @include('tgs::_node-preview-content', ['line' => $line, 'node' => $node])
                </span>
            </div>
        </div>

        {{-- Node header priority/compaction plan (Task A): the three independent
        foreign/shared/usage-count indicators visually collapse into ONE compact clickable
        chip — icon + place count, `shrink-0` so it can never crowd out the preview span's
        `flex-1 truncate` next to it (the layout bug this plan fixes). The three original
        data-testid values are preserved (only their shared visual shape changes) so they
        keep meaning "which kind of usage this is" for tests and any external tooling.
        Detail text that used to render inline (referenceSourceLabel()/shared explanation/
        the "Используется в N местах" sentence) moves into the chip's tooltip instead. --}}
        @php
            // blockPlacementsCount() (total live BlockPlacement rows, ANY host), not
            // usagePlacesCount() (distinct root HOSTS) — a block referenced a second time on
            // its OWN host (insertFromToken()'s "as reference" option) resolves to a single
            // root host, so usagePlacesCount() never clears its own `> 1` gate and the ORIGIN
            // showed no chip at all even though a second, visibly-badged placement of the
            // same block sits right there (live-review finding). blockPlacementsCount() isn't
            // collapsed by a shared host, so it catches this case too.
            $placementsCount = $this->blockPlacementsCount($node['id']);
            $isReference = $isForeign || $this->isReferencePlacement($node['placementId'], $node['id']);
            // A child that only INHERITS its parent container's placementsCount (the same
            // count, walked up through the same structural edge) is noise: "Column used in 3
            // places" already told the user everything its every direct child would repeat. A
            // reference chip is never deduped this way — it signals THIS placement's own
            // identity, not an inherited count, so it always shows once placementsCount > 1.
            $showUsageChip = $isReference
                || ($placementsCount > 1 && $placementsCount !== $parentPlacementsCount);
        @endphp
        {{-- Stage 2: usage/reference chip + lock badge grouped into their own row-wrapper
        (mirroring the toolbar's existing wrapper below) so the card-mode `flex-col` header
        gets a clean "indicators" row instead of each chip stretching to its own full-width
        line. --}}
        @if ($showUsageChip || $lock['effectivelyLocked'])
        <div class="flex flex-wrap items-center gap-1.5">
        @if ($showUsageChip)
            @php
                $usagePlaces = $this->usagePlaces($node['id']);
                $usageCountTooltip = trans_choice('tgs::filament.tgs.canvas.usage.tooltip', $placementsCount, ['count' => $placementsCount]);
                if ($isForeign) {
                    $usageChipTestId = 'foreign-badge';
                    $usageChipIcon = 'heroicon-o-link';
                    $usageChipColor = 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-400';
                    $usageChipTooltip = $this->referenceSourceLabel($node['id']).' — '.$usageCountTooltip;
                } elseif ($isReference) {
                    $usageChipTestId = 'shared-badge';
                    $usageChipIcon = 'heroicon-o-link';
                    $usageChipColor = 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-400';
                    $usageChipTooltip = __('tgs::filament.tgs.reference.shared_tooltip').' — '.$usageCountTooltip;
                } else {
                    $usageChipTestId = 'usage-count';
                    $usageChipIcon = 'heroicon-o-square-2-stack';
                    $usageChipColor = 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300';
                    $usageChipTooltip = $usageCountTooltip;
                }
            @endphp
            <div class="relative shrink-0" x-data="{ usageOpen: false }" x-on:click.outside="usageOpen = false">
                <button
                    type="button"
                    data-testid="{{ $usageChipTestId }}"
                    x-on:click="usageOpen = !usageOpen"
                    x-tooltip="{ content: @js($usageChipTooltip), theme: 'dark' }"
                    class="shrink-0 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium hover:opacity-80 {{ $usageChipColor }}"
                >
                    <x-filament::icon :icon="$usageChipIcon" class="h-3 w-3 shrink-0" />
                    {{ $placementsCount }}
                </button>

                <div
                    x-show="usageOpen"
                    x-cloak
                    x-transition
                    data-testid="usage-list"
                    class="absolute right-0 z-10 mt-1 w-64 rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="px-3 py-1 text-xs font-semibold text-gray-500 dark:text-gray-400">
                        {{ __('tgs::filament.tgs.canvas.usage.title') }}
                    </div>
                    @foreach ($usagePlaces as $place)
                        <div class="px-3 py-1 text-sm text-gray-700 dark:text-gray-300">
                            @if ($place['url'] !== null)
                                <a href="{{ $place['url'] }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $place['label'] }}
                                </a>
                            @else
                                <span>{{ $place['label'] }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($lock['effectivelyLocked'])
            <span
                data-testid="lock-badge"
                x-tooltip="{ content: @js($this->lockHint($node['id'])), theme: 'dark' }"
                class="shrink-0 inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-400"
            >
                <x-filament::icon icon="heroicon-o-lock-closed" class="h-3 w-3" />
            </span>
        @endif
        </div>
        @endif

        <div class="flex shrink-0 items-center gap-1 {{ $toolbarVisibility }}">
            @if ($node['slots'] !== [])
                <button
                    type="button"
                    x-on:click="collapsed = !collapsed"
                    class="flex shrink-0 items-center gap-0.5 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    title="Свернуть"
                >
                    {{-- Task C (node header priority/compaction plan): the container's child
                    count used to render as "N вложенных блоков" text in the preview line,
                    competing with the block's own preview for space. It lives here now,
                    always visible (collapsed or not) — the grilled decision was that a
                    permanent number is simpler than an extra x-show toggle for a number
                    that's mostly useful while collapsed. --}}
                    @if ($node['childCount'] > 0)
                        <span data-testid="child-count" class="shrink-0 text-[11px] text-gray-400">{{ $node['childCount'] }}</span>
                    @endif
                    <svg x-show="!collapsed" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    <svg x-show="collapsed" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.832l-3.71 3.938a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd" /></svg>
                </button>
            @endif

            {{-- #35: the read-only VIEW affordance must show for EVERY block, including a
            foreign reference — letting the user open a block to look at its content is exactly
            what read-only is for. A reference shares the source's block_id, so editBlockAction's
            fillForm (Block::find($block)->data) fills the disabled schema from the SOURCE row.
            The mutating edit/settings affordance stays hidden for a foreign block, because
            editBlockData()/commitDraft() already no-op on a non-owned block (CanvasPersistence
            ownership gate) — offering a control that silently does nothing is the thing we avoid. --}}
            @if ($readonly)
                {{-- Read-only design (§3, Task 4): edit -> view. Opens the SAME editBlockAction in
                its disabled/no-submit shape (see TgsCanvas::editBlockAction()); lock state is
                irrelevant here — a lock gates editing rights, not viewing. Shown for foreign too
                (#35 fix). --}}
                <button
                    type="button"
                    data-testid="view-block"
                    wire:click="mountAction('editBlockAction', {{ \Illuminate\Support\Js::from(['block' => $node['id'], 'type' => $node['type'], 'isRoot' => $parentBlockId === null]) }})"
                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    title="{{ __('tgs::filament.tgs.actions.view_block.label') }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path d="M10 12.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z" /><path fill-rule="evenodd" d="M.664 10.59a1.651 1.651 0 010-1.186A10.004 10.004 0 0110 3c4.257 0 7.893 2.66 9.336 6.41.147.381.146.804 0 1.186A10.004 10.004 0 0110 17c-4.257 0-7.893-2.66-9.336-6.41zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" /></svg>
                </button>
            @elseif (!$isForeign)
                {{-- Writable + owned: the mutating settings affordance (with the lock-disabled
                variant). Hidden for foreign (editing a reference is a no-op). --}}
                @if ($lock['lockedForUser'])
                    <span x-tooltip="{ content: 'Недоступно: блок заблокирован', theme: 'dark' }">
                        <button
                            type="button"
                            @disabled(true)
                            wire:click="mountAction('editBlockAction', {{ \Illuminate\Support\Js::from(['block' => $node['id'], 'type' => $node['type'], 'isRoot' => $parentBlockId === null]) }})"
                            class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300 opacity-40"
                            title="Настройки"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path fill-rule="evenodd" d="M7.84 1.804A1 1 0 018.82 1h2.36a1 1 0 01.98.804l.331 1.652a6.993 6.993 0 011.929 1.115l1.598-.54a1 1 0 011.186.447l1.18 2.044a1 1 0 01-.205 1.251l-1.267 1.113a7.047 7.047 0 010 2.228l1.267 1.113a1 1 0 01.205 1.251l-1.18 2.044a1 1 0 01-1.186.447l-1.598-.54a6.993 6.993 0 01-1.929 1.115l-.33 1.652a1 1 0 01-.98.804H8.82a1 1 0 01-.98-.804l-.331-1.652a6.993 6.993 0 01-1.929-1.115l-1.598.54a1 1 0 01-1.186-.447l-1.18-2.044a1 1 0 01.205-1.251l1.267-1.114a7.05 7.05 0 010-2.227L1.821 7.773a1 1 0 01-.205-1.251l1.18-2.044a1 1 0 011.186-.447l1.598.54A6.993 6.993 0 017.51 3.456l.33-1.652zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" /></svg>
                        </button>
                    </span>
                @else
                    <button
                        type="button"
                        wire:click="mountAction('editBlockAction', {{ \Illuminate\Support\Js::from(['block' => $node['id'], 'type' => $node['type'], 'isRoot' => $parentBlockId === null]) }})"
                        class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                        title="Настройки"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path fill-rule="evenodd" d="M7.84 1.804A1 1 0 018.82 1h2.36a1 1 0 01.98.804l.331 1.652a6.993 6.993 0 011.929 1.115l1.598-.54a1 1 0 011.186.447l1.18 2.044a1 1 0 01-.205 1.251l-1.267 1.113a7.047 7.047 0 010 2.228l1.267 1.113a1 1 0 01.205 1.251l-1.18 2.044a1 1 0 01-1.186.447l-1.598-.54a6.993 6.993 0 01-1.929 1.115l-.33 1.652a1 1 0 01-.98.804H8.82a1 1 0 01-.98-.804l-.331-1.652a6.993 6.993 0 01-1.929-1.115l-1.598.54a1 1 0 01-1.186-.447l-1.18-2.044a1 1 0 01.205-1.251l1.267-1.114a7.05 7.05 0 010-2.227L1.821 7.773a1 1 0 01-.205-1.251l1.18-2.044a1 1 0 011.186-.447l1.598.54A6.993 6.993 0 017.51 3.456l.33-1.652zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" /></svg>
                    </button>
                @endif
            @endif

            {{-- Kebab menu (Task 2, kebab-fix plan; widened 2026-07-23 UX follow-up to declutter
            the row — lock/unlock, history, duplicate, detach and delete all moved in here,
            leaving only settings/view + collapse always visible; see git history for their
            prior standalone-button shape, each item below keeps its EXACT original wire:click/
            data-testid/visibility condition, just re-skinned as a menu row instead of an icon
            button).
            No visibility guard on the trigger itself (2026-07-23, token-slice follow-up):
            "Копировать" below has NO condition at all — always visible, in read-only, on a
            foreign/locked block alike (copying an id never mutates anything and never depends
            on ownership) — so the kebab always has at least one entry now, and the previous
            $kebabHasEntries computed check (which mirrored "would at least one item below
            render") is dead code.
            Own x-data scope (nested inside the toolbar, not the node root's
            x-data="{ collapsed: false }") so opening the menu never interferes with collapse
            state. Placed last (rightmost) — a menu trigger reads more naturally at the trailing
            edge than sandwiched between primary actions. --}}
            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                    <button
                        type="button"
                        data-testid="node-menu"
                        x-on:click="open = !open"
                        class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                        title="{{ __('tgs::filament.tgs.actions.node_menu.label') }}"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z" /></svg>
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        x-transition
                        class="absolute right-0 z-10 mt-1 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800"
                    >
                        {{-- Lock/unlock govern the block's OWN row, which lives with its owning
                        host — meaningless on a foreign reference (locking it here would lock
                        the shared original from an unrelated page). --}}
                        @if ($canManageLocks && !$lock['directlyLocked'] && !$isForeign && !$readonly)
                            <button
                                type="button"
                                data-testid="lock-block"
                                x-on:click="open = false"
                                wire:click="mountAction('lockBlockAction', { block: '{{ $node['id'] }}' })"
                                class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                <x-filament::icon icon="heroicon-o-lock-closed" class="h-4 w-4 shrink-0" />
                                Заблокировать
                            </button>
                        @endif

                        @if ($canManageLocks && $lock['directlyLocked'] && !$isForeign && !$readonly)
                            <button
                                type="button"
                                data-testid="unlock-block"
                                x-on:click="open = false"
                                wire:click="mountAction('unlockBlockAction', { block: '{{ $node['id'] }}' })"
                                class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                <x-filament::icon icon="heroicon-o-lock-open" class="h-4 w-4 shrink-0" />
                                Разблокировать
                            </button>
                        @endif

                        {{-- §13: history is a non-mutating read, visible regardless of
                        $readonly (like the settings/view button) — only hidden for a foreign
                        reference, mirroring editBlockAction's own visibility rule, since
                        revertVersion() would no-op on a non-owned/non-orphan block anyway (same
                        ownership gate as editBlockData()). Dispatches a GLOBAL event rather
                        than calling a TgsCanvas method directly — the drawer lives in its own
                        Livewire component (TgsBlockHistoryDrawer), deliberately decoupled from
                        this component's wire:id/morph pass (see that class's docblock for why). --}}
                        @unless ($isForeign)
                            <button
                                type="button"
                                data-testid="history-block"
                                x-on:click="open = false"
                                wire:click="$dispatch('open-block-history', { blockId: '{{ $node['id'] }}' })"
                                class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                <x-filament::icon icon="bi-clock-history" class="h-4 w-4 shrink-0" />
                                {{ __('tgs::filament.tgs.actions.history.label') }}
                            </button>
                        @endunless

                        {{-- Duplicate is already a no-op on a foreign block (duplicateRootBlock/
                        duplicateChildBlock gate on blockBelongsToHost) — hidden here for the
                        same reason as edit: don't offer a control with no effect. Also hidden
                        outright in read-only (Task 3) — a mutating button that would just 403. --}}
                        @unless ($isForeign || $readonly)
                            @if ($lock['lockedForUser'])
                                <span x-tooltip="{ content: 'Недоступно: блок заблокирован', theme: 'dark' }" class="block">
                                    <button
                                        type="button"
                                        @disabled(true)
                                        wire:click="{{ $parentBlockId === null ? "duplicateRootBlock('{$node['id']}')" : "duplicateChildBlock('{$parentBlockId}', '{$parentSlot}', '{$node['id']}')" }}"
                                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-gray-400 opacity-40 dark:text-gray-500"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0"><path d="M7 3.5A1.5 1.5 0 018.5 2h3.879a1.5 1.5 0 011.06.44l3.122 3.12A1.5 1.5 0 0117 6.622V12.5a1.5 1.5 0 01-1.5 1.5h-1v-3.379a3 3 0 00-.879-2.121L10.5 5.379A3 3 0 008.379 4.5H7v-1z" /><path d="M4.5 6A1.5 1.5 0 003 7.5v9A1.5 1.5 0 004.5 18h8a1.5 1.5 0 001.5-1.5v-5.879a1.5 1.5 0 00-.44-1.06L10.44 6.439A1.5 1.5 0 009.378 6H4.5z" /></svg>
                                        Дублировать
                                    </button>
                                </span>
                            @else
                                <button
                                    type="button"
                                    x-on:click="open = false"
                                    wire:click="{{ $parentBlockId === null ? "duplicateRootBlock('{$node['id']}')" : "duplicateChildBlock('{$parentBlockId}', '{$parentSlot}', '{$node['id']}')" }}"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0"><path d="M7 3.5A1.5 1.5 0 018.5 2h3.879a1.5 1.5 0 011.06.44l3.122 3.12A1.5 1.5 0 0117 6.622V12.5a1.5 1.5 0 01-1.5 1.5h-1v-3.379a3 3 0 00-.879-2.121L10.5 5.379A3 3 0 008.379 4.5H7v-1z" /><path d="M4.5 6A1.5 1.5 0 003 7.5v9A1.5 1.5 0 004.5 18h8a1.5 1.5 0 001.5-1.5v-5.879a1.5 1.5 0 00-.44-1.06L10.44 6.439A1.5 1.5 0 009.378 6H4.5z" /></svg>
                                    Дублировать
                                </button>
                            @endif
                        @endunless

                        {{-- Token-срез (docs/superpowers/specs/2026-07-20-block-reuse-mechanisms-design.md):
                        puts the block's raw id ("token") on the OS clipboard — pure client-side,
                        no server round-trip, no guardWritable() (nothing is mutated). Reuses
                        Filament's OWN copy-to-clipboard mechanism (the exact x-on:click body
                        Filament\Schemas\Components\Text's ->copyable() renders, see
                        vendor/filament/schemas/resources/views/components/text.blade.php) —
                        $tooltip is Filament's bundled Alpine magic, not custom JS. Always
                        visible: read-only, foreign/reference, locked — copying an id changes
                        nothing and depends on no ownership, so none of those gates apply here,
                        unlike every other entry in this menu. Placed right after Duplicate —
                        both create a reusable artifact from this block, just with a different
                        destination (same page vs. anywhere else via a pasted id). --}}
                        <button
                            type="button"
                            data-testid="copy-token"
                            x-on:click="
                                open = false
                                window.navigator.clipboard.writeText(@js($node['id']))
                                $tooltip(@js(__('filament::components/copyable.messages.copied')), {
                                    theme: $store.theme,
                                    timeout: 2000,
                                })
                            "
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0"><path fill-rule="evenodd" d="M13 4.5a2.5 2.5 0 11.702 1.737L6.97 9.604a2.518 2.518 0 010 .792l6.733 3.367a2.5 2.5 0 11-.671 1.341l-6.733-3.367a2.5 2.5 0 110-3.475l6.733-3.366A2.52 2.52 0 0113 4.5z" clip-rule="evenodd" /></svg>
                            {{ __('tgs::filament.tgs.actions.copy_token.label') }}
                        </button>

                        {{-- Detach (Task 3, 2a-1): only meaningful on a foreign block — turns
                        the reference into an owned clone in-place. Restricted to the
                        reference's own root ($parentIsForeign false): a node deeper inside a
                        foreign subtree has its containerId resolve to the FOREIGN parent
                        block, not the host, so mutating it would edit the shared original's
                        placement seen by every consuming host — see finding #1, final review. --}}
                        @if ($isForeign && !$lock['lockedForUser'] && !$parentIsForeign && !$readonly)
                            <button
                                type="button"
                                data-testid="detach-block"
                                x-on:click="open = false"
                                wire:click="mountAction('detachBlockAction', {{ \Illuminate\Support\Js::from([
                                    'block' => $node['id'],
                                    'containerType' => $detachContainer['containerType'],
                                    'containerId' => $detachContainer['containerId'],
                                    'slot' => $detachContainer['slot'],
                                ]) }})"
                                class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0"><path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H3.989a.75.75 0 00-.75.75v4.242a.75.75 0 001.5 0v-2.43l.31.31a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.23-3.723a.75.75 0 00.219-.53V2.929a.75.75 0 00-1.5 0V5.36l-.31-.31A7 7 0 003.239 8.188a.75.75 0 101.448.389A5.5 5.5 0 0113.89 6.11l.311.31h-2.432a.75.75 0 000 1.5h4.243a.75.75 0 00.53-.219z" clip-rule="evenodd" /></svg>
                                {{ __('tgs::filament.tgs.actions.detach.label') }}
                            </button>
                        @endif

                        {{-- Add to library: deep-copies the block into the shared library host
                        (addToLibrary() → CanvasPersistence::copyBlockInto) — the block on the
                        canvas is untouched, no reference is created. Also hidden outright when
                        the mounted canvas IS the library itself ($isLibraryHost) — a block here
                        is already in the library, "add to library" would just self-copy it. --}}
                        @unless ($isForeign || $isLibraryHost || $readonly)
                            <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>

                            @if ($lock['lockedForUser'])
                                <span x-tooltip="{ content: 'Недоступно: блок заблокирован', theme: 'dark' }" class="block">
                                    <button
                                        type="button"
                                        data-testid="add-to-library"
                                        @disabled(true)
                                        wire:click="mountAction('addToLibraryAction', {{ \Illuminate\Support\Js::from(['block' => $node['id'], 'parent' => $parentBlockId, 'slot' => $parentSlot]) }})"
                                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-gray-400 opacity-40 dark:text-gray-500"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M10.75 2.75a.75.75 0 00-1.5 0V4.5h-2A2.75 2.75 0 004.5 7.25v11.5a.75.75 0 001.28.53L10 15.06l4.22 4.22a.75.75 0 001.28-.53V7.25a2.75 2.75 0 00-2.75-2.75h-2V2.75z" /></svg>
                                        {{ __('tgs::filament.tgs.actions.add_to_library.label') }}
                                    </button>
                                </span>
                            @else
                                <button
                                    type="button"
                                    data-testid="add-to-library"
                                    x-on:click="open = false"
                                    wire:click="mountAction('addToLibraryAction', {{ \Illuminate\Support\Js::from(['block' => $node['id'], 'parent' => $parentBlockId, 'slot' => $parentSlot]) }})"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M10.75 2.75a.75.75 0 00-1.5 0V4.5h-2A2.75 2.75 0 004.5 7.25v11.5a.75.75 0 001.28.53L10 15.06l4.22 4.22a.75.75 0 001.28-.53V7.25a2.75 2.75 0 00-2.75-2.75h-2V2.75z" /></svg>
                                    {{ __('tgs::filament.tgs.actions.add_to_library.label') }}
                                </button>
                            @endif
                        @endunless

                        {{-- Delete stays the same method for owned and foreign blocks —
                        CanvasPersistence::deleteBlock() already just unlinks the placement,
                        never destroys a shared original (that invariant predates this task).
                        Foreign gets a different title/modal so it doesn't read as "delete the
                        shared block". Hidden entirely for a node deeper inside a foreign
                        subtree ($parentIsForeign): its parent/slot arguments resolve to the
                        FOREIGN block, so deleteChildBlock() would unlink the placement inside
                        the shared container itself, visible to every host referencing it — see
                        finding #1, final review. Hidden outright in read-only (Task 3).
                        Bottom of the menu, separated and red — the destructive action, kept
                        away from Duplicate so a misclick can't turn one into the other. --}}
                        @unless ($parentIsForeign || $readonly)
                            <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>

                            @if ($lock['lockedForUser'])
                                <span x-tooltip="{ content: 'Недоступно: блок заблокирован', theme: 'dark' }" class="block">
                                    <button
                                        type="button"
                                        @disabled(true)
                                        wire:click="mountAction('deleteBlockAction', {{ \Illuminate\Support\Js::from(['block' => $node['id'], 'parent' => $parentBlockId, 'slot' => $parentSlot]) }})"
                                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-red-300 opacity-40 dark:text-red-800"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0"><path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 10.23 1.482l.149-.022.841 10.518A2.75 2.75 0 007.596 19h4.807a2.75 2.75 0 002.742-2.53l.841-10.52.149.023a.75.75 0 00.23-1.482A41.03 41.03 0 0014 4.193V3.75A2.75 2.75 0 0011.25 1h-2.5zM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4zM8.58 7.72a.75.75 0 00-1.5.06l.3 7.5a.75.75 0 101.5-.06l-.3-7.5zm4.34.06a.75.75 0 10-1.5-.06l-.3 7.5a.75.75 0 101.5.06l.3-7.5z" clip-rule="evenodd" /></svg>
                                        {{ $isForeign ? __('tgs::filament.tgs.canvas.delete.unlink_submit') : 'Удалить' }}
                                    </button>
                                </span>
                            @else
                                <button
                                    type="button"
                                    x-on:click="open = false"
                                    wire:click="mountAction('deleteBlockAction', {{ \Illuminate\Support\Js::from(['block' => $node['id'], 'parent' => $parentBlockId, 'slot' => $parentSlot]) }})"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0"><path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 10.23 1.482l.149-.022.841 10.518A2.75 2.75 0 007.596 19h4.807a2.75 2.75 0 002.742-2.53l.841-10.52.149.023a.75.75 0 00.23-1.482A41.03 41.03 0 0014 4.193V3.75A2.75 2.75 0 0011.25 1h-2.5zM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4zM8.58 7.72a.75.75 0 00-1.5.06l.3 7.5a.75.75 0 101.5-.06l-.3-7.5zm4.34.06a.75.75 0 10-1.5-.06l-.3 7.5a.75.75 0 101.5.06l.3-7.5z" clip-rule="evenodd" /></svg>
                                    {{ $isForeign ? __('tgs::filament.tgs.canvas.delete.unlink_submit') : 'Удалить' }}
                                </button>
                            @endif
                        @endunless
                    </div>
                </div>
        </div>
    </div>

    {{-- Stage 2 (node header priority/compaction plan), revised: the header row above never
    reflows — below the 360px `@container` breakpoint this full-width block takes over from the
    inline preview span instead (which hides itself via `@max-[360px]:hidden`), wrapping onto
    two lines (`line-clamp-2`) rather than a single truncated line. Same
    `_node-preview-content.blade.php` partial as the inline version, so the branching logic
    (preview text / childCount / filled-not_filled / empty) isn't duplicated. --}}
    {{-- `line-clamp-2` itself sets `display: -webkit-box` (needed for the clamp to work at
    all) — giving it as the responsive variant here, instead of a separate unprefixed
    `line-clamp-2` alongside `@max-[360px]:block`, avoids the two rules fighting over `display`
    (whichever wins turns either the visibility toggle or the 2-line clamp into a no-op). --}}
    <div data-testid="preview-block" class="hidden @max-[360px]:line-clamp-2 px-3 pb-2 text-xs text-gray-500 dark:text-gray-400">
        @include('tgs::_node-preview-content', ['line' => $line, 'node' => $node])
    </div>

    {{-- Library page only: how many times this block is used elsewhere as a shared
    reference. Root-level library blocks only (depth === 0) — a block nested inside a
    composite library block isn't independently referenceable via "Из библиотеки", so a
    usage count wouldn't mean anything there. --}}
    @if ($isLibraryHost && $depth === 0)
        @php $usageCount = $this->libraryUsageCount($node['id']); @endphp
        <div class="border-t border-gray-100 px-3 py-1.5 text-[11px] text-gray-400 dark:border-gray-800 dark:text-gray-500">
            @if ($usageCount > 0)
                {{ trans_choice('tgs::filament.tgs.canvas.library.usage_count', $usageCount, ['count' => $usageCount]) }}
            @else
                {{ __('tgs::filament.tgs.canvas.library.usage_count_none') }}
            @endif
        </div>
    @endif

    @if ($node['slots'] !== [])
        @php
            $isRowLayout = ($meta['layout'] ?? 'stack') === 'row';
            // Stage 2 (node header priority/compaction plan): a row-layout block (currently
            // only double_column — see TgsBlockLayout::Row) nested inside ANOTHER row-layout
            // ancestor no longer splits 50/50 — it renders as a plain single-column stack
            // instead. Two nested 50/50 splits compound (100% -> 50% -> 25%...), which is what
            // collapsed the node header into unreadable overlap on the live check. Only
            // row-layout ancestors count toward the depth — an ordinary stack container
            // ("column") in between neither resets nor contributes to it, so a double_column
            // nested inside a plain column is still the FIRST row-layout in its chain and
            // renders normally.
            $effectiveIsRowLayout = $isRowLayout && $parentRowLayoutDepth === 0;
            $rowLayoutDepth = $isRowLayout ? $parentRowLayoutDepth + 1 : $parentRowLayoutDepth;
        @endphp
        <div x-show="!collapsed" class="{{ $effectiveIsRowLayout ? 'flex gap-3' : 'space-y-2' }} border-t border-gray-100 px-3 py-2 dark:border-gray-800">
            @foreach ($node['slots'] as $slotName => $slotNodes)
                {{-- Row layout (double_column, Task 4): fixed 50/50 split via flex-1 on every
                slot regardless of count — deliberately NOT mirroring the block's own
                left_column_width/right_column_width settings (those govern the FRONTEND
                render only, see this task's plan doc). Same border-left divider as the
                stack layout, just arranged side-by-side instead of one under another. --}}
                {{-- Stage 2 (node header priority/compaction plan): `@container` here
                (Tailwind v4 built-in, `container-type: inline-size` by default) makes every
                node placed directly in this slot query ITS OWN available width — the
                `@max-[360px]:` variants on the node header above react to this, not to the
                viewport. Applied to EVERY slot (not just row-layout ones): a plain stack can
                get just as narrow from accumulated indentation on deep nesting.

                No `ml-3` before the border on a stack slot (fixed after live review): with
                margin + `border-l-2` + `pl-3`, border-to-content indent was 26px per nesting
                level — nearly double the 13-14px "frame to content" gap on an ordinary card,
                and on deep nesting the extra 12px/level compounded fast. Border + `pl-3` alone
                (14px) already reads as a clear nesting guide without wasting width. --}}
                <div class="@container {{ $effectiveIsRowLayout ? 'min-w-0 flex-1' : '' }} border-l-2 border-gray-200 pl-3 dark:border-gray-700">
                    @if (count($node['slots']) > 1)
                        <div class="mb-1 text-xs font-semibold tracking-wide text-gray-400 dark:text-gray-500">
                            Слот: {{ $this->slotLabel($slotName) }}
                        </div>
                    @endif

                    {{-- Foreign blocks are read-only content: no adding into their slots
                    (openPicker/addChildBlock already gate on blockBelongsToHost) and no
                    reordering their children (reorderChild gates on the parent's ownership too) —
                    x-sortable is simply omitted, and $isForeign propagates down as
                    $parentIsForeign so each child suppresses its own drag handle. Read-only
                    (Task 3) suppresses every add affordance (empty-slot button, quick-add,
                    insert-gap) and the slot's own x-sortable the same way — an empty slot in
                    read-only renders the neutral "empty" label instead of an add button so it
                    reads as empty, not broken. --}}
                    @if ($slotNodes === [])
                        @if ($readonly)
                            <div class="rounded-md border border-dashed border-gray-300 p-3 text-center text-sm text-gray-400 dark:border-gray-700 dark:text-gray-600">
                                {{ __('tgs::filament.tgs.canvas.empty') }}
                            </div>
                        @elseif (!$lock['lockedForUser'] && !$isForeign)
                            @php $single = $this->slotSingleChild($node['type'], $slotName, $node['id']); @endphp
                            <button
                                type="button"
                                wire:click="{!! $single !== null
                                    ? "quickAddChild('{$node['id']}', '{$slotName}')"
                                    : "openPicker('{$node['id']}', '{$slotName}')" !!}"
                                wire:loading.attr="disabled"
                                wire:target="quickAddChild,openPicker"
                                class="w-full rounded-md border border-dashed border-gray-300 p-3 text-center text-sm text-gray-400 hover:border-primary-400 hover:text-primary-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-600"
                            >
                                + {{ $this->slotAddLabel($node['type'], $slotName, $node['id']) }}
                            </button>
                        @endif
                    @else
                        <div
                            @unless ($isForeign || $readonly)
                                x-sortable
                                x-sortable-group="tgs-canvas-{{ $canvasId }}"
                                data-container-type="block"
                                data-container-id="{{ $node['id'] }}"
                                data-slot="{{ $slotName }}"
                                x-on:end.stop="$event.from === $event.to ? $wire.reorderChild('{{ $node['id'] }}', '{{ $slotName }}', $event.target.sortable.toArray()) : crossSlotMove($event)"
                            @endunless
                        >
                            @foreach ($slotNodes as $childIndex => $childNode)
                                @unless ($lock['lockedForUser'] || $isForeign || $readonly)
                                    @include('tgs::_insert-gap', ['parentId' => $node['id'], 'slot' => $slotName, 'position' => $childIndex])
                                @endunless
                                @include('tgs::_node', [
                                    'node' => $childNode,
                                    'path' => $path . '.' . $slotName . '.' . $childIndex,
                                    'depth' => $depth + 1,
                                    'parentBlockId' => $node['id'],
                                    'parentSlot' => $slotName,
                                    'parentIsForeign' => $isForeign,
                                    'parentPlacementsCount' => $placementsCount,
                                    'parentRowLayoutDepth' => $rowLayoutDepth,
                                ])
                            @endforeach
                        </div>

                        @unless ($lock['lockedForUser'] || $isForeign || $readonly)
                            @php $single = $this->slotSingleChild($node['type'], $slotName, $node['id']); @endphp
                            <button
                                type="button"
                                wire:click="{!! $single !== null
                                    ? "quickAddChild('{$node['id']}', '{$slotName}')"
                                    : "openPicker('{$node['id']}', '{$slotName}')" !!}"
                                wire:loading.attr="disabled"
                                wire:target="quickAddChild,openPicker"
                                class="mt-1 text-xs text-gray-400 hover:text-primary-500 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                + {{ $this->slotAddLabel($node['type'], $slotName, $node['id']) }}
                            </button>
                        @endunless
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
