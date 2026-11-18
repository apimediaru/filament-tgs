{{-- Полная раскладка канваса (Task 9, plan B2): тулбар вставки корневого блока + индикатор автосейва.
Условный футер (canvas-usability-polish, Task C fallback): на длинной ленте верхняя кнопка уезжает за
экран при скролле — sticky-обёртка конфликтовала со sticky-вкладками ресурса Filament (двойной прилипший
бар), поэтому вместо sticky используется Alpine-замер переполнения корневого контейнера
($el.scrollHeight > 85vh) и футер, который появляется ровно тогда, когда шапка уже не видна — тот же
`_add-block-bar` partial, что и в шапке, только обёрнутый в x-show. --}}
<div
    x-data="{
        overflowing: false,
        checkOverflow() {
            this.overflowing = this.$el.scrollHeight > window.innerHeight * 0.85;
        },
        @unless ($readonly)
            // Слайс E (DnD между слотами): единая точка для оба x-sortable-контейнера канваса
            // (этот корневой список и каждый слотовый список в _node.blade.php, вложенные внутрь
            // этого x-data и потому видящие этот метод по цепочке Alpine-скоупов). Вызывается
            // ТОЛЬКО когда $event.from !== $event.to (перенос между разными списками) —
            // внутри-слотовый реордер по-прежнему идёт напрямую через reorderRoot()/reorderChild(),
            // см. вызовы ниже. Гейт вокруг всего метода — не косметика: readonly-рендер не должен
            // содержать даже текстовый вызов $wire.moveBlockAcrossSlots(...) (TgsCanvasReadonlyBladeGuardTest
            // сканирует HTML на литеральное присутствие маркера мутирующего метода, а не только на то,
            // подключён ли он к активному x-on).
            crossSlotMove(event) {
                const ids = event.to.sortable.toArray();
                const blockId = event.item.getAttribute('x-sortable-item');
                const idx = ids.indexOf(blockId);
                const afterBlockId = idx > 0 ? ids[idx - 1] : null;
                const beforeBlockId = idx > -1 && idx < ids.length - 1 ? ids[idx + 1] : null;

                this.$wire.moveBlockAcrossSlots(
                    blockId,
                    event.from.dataset.containerType, event.from.dataset.containerId, event.from.dataset.slot,
                    event.to.dataset.containerType, event.to.dataset.containerId, event.to.dataset.slot,
                    afterBlockId, beforeBlockId,
                );
            },
        @endunless
    }"
    x-init="checkOverflow(); window.addEventListener('resize', () => checkOverflow()); window.addEventListener('scroll', () => checkOverflow());"
>
    {{-- Only the actual canvas content carries the vertical rhythm (space-y-3). The trailing
    overlays below — <x-filament-actions::modals /> and the history-drawer Livewire component
    (whose root is an always-present, empty in-flow <div>) — are deliberately OUTSIDE this
    wrapper: inside it, space-y-3 would add a margin-top before them, showing up as a phantom gap
    under the content (most visible on an empty canvas, where there's nothing else below). --}}
    <div class="space-y-3">
        @if ($documentationUrl = \ApiMedia\Tgs\Support\TgsDocumentation::url())
            <a href="{{ $documentationUrl }}" target="_blank" rel="noopener noreferrer">
                {{ __('tgs::documentation.instruction') }}
            </a>
        @endif
        {{-- Read-only (Task 3, read-only design spec §3): every mutating affordance is simply not
        rendered rather than dimmed — the add-block bar (header + footer), the insert drawer, and
        the per-node add/insert-gap buttons all disappear. The tree itself, previews, badges and
        the §12 lock badge stay intact; see _node.blade.php for the per-node gating. --}}
        @unless ($readonly)
            @include('tgs::_add-block-bar', ['autosaveKey' => 'autosave-indicator-header', 'showCollapseToggle' => true])
        @endunless

        @if ($this->tree === [])
            <div class="rounded-md border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-700 dark:text-gray-600">
                @if ($readonly)
                    {{ __('tgs::filament.tgs.canvas.empty') }}
                @else
                    Пока нет блоков — начните с «Добавить блок» выше.
                @endif
            </div>
        @else
            <div
                @unless ($readonly)
                    x-sortable
                    x-sortable-group="tgs-canvas-{{ $canvasId }}"
                    data-container-type="{{ $this->host()->containerType }}"
                    data-container-id="{{ $this->host()->containerId }}"
                    data-slot="{{ $this->host()->slot }}"
                    x-on:end.stop="$event.from === $event.to ? $wire.reorderRoot($event.target.sortable.toArray()) : crossSlotMove($event)"
                @endunless
            >
                @foreach ($this->tree as $rootIndex => $node)
                    @unless ($readonly)
                        @include('tgs::_insert-gap', ['parentId' => null, 'slot' => null, 'position' => $rootIndex])
                    @endunless
                    @include('tgs::_node', ['node' => $node, 'path' => (string) $rootIndex, 'depth' => 0])
                @endforeach
            </div>

            @unless ($readonly)
                <div x-cloak x-show="overflowing">
                    @include('tgs::_add-block-bar', ['autosaveKey' => 'autosave-indicator-footer', 'showCollapseToggle' => false])
                </div>
            @endunless
        @endif
    </div>

    <x-filament-actions::modals />

    {{-- §13: history drawer — its OWN Livewire component (TgsBlockHistoryDrawer), deliberately
    NOT inline template here. See that class's docblock: inlining its markup into THIS
    component's own root previously broke the forceRender()-triggered auto-open of
    editBlockAction after addRootBlock() — a fixed-delay dispatch/morph-timing race in
    Livewire/Alpine core, not a position/key issue. Its own wire:id keeps its markup out of
    TgsCanvas's morph pass entirely. --}}
    @livewire('tgs::tgs-block-history-drawer', ['canvasId' => $canvasId, 'readonly' => $readonly], key('tgs-block-history-drawer-'.$canvasId))

    {{-- Этап 4, Слайс D: корзина канваса — своя, ОТДЕЛЬНАЯ Livewire-разметка (TgsTrashDrawer),
    той же причины ради, что и history-drawer выше. Требование 6.6: read-only канвас корзину
    не рендерит ВООБЩЕ (не просто скрывает кнопку открытия) — @unless здесь, а не внутри самого
    компонента. --}}
    @unless ($readonly)
        @livewire('tgs::tgs-trash-drawer', ['canvasId' => $canvasId, 'readonly' => $readonly], key('tgs-trash-drawer-'.$canvasId))
    @endunless
</div>
