<?php

namespace ApiMedia\Tgs\Livewire;

use ApiMedia\Tgs\Models\Block;
use ApiMedia\Tgs\Models\BlockVersion;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Services\CanvasPersistence;
use ApiMedia\Tgs\Support\BlockVersionDiffer;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsAuthorization;
use ApiMedia\Tgs\Support\TgsBlock;
use ApiMedia\Tgs\Support\TgsModels;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

use function abort_if;
use function array_keys;
use function array_unique;
use function count;

/**
 * §13: block history drawer, deliberately a SEPARATE Livewire component from TgsCanvas —
 * NOT a template fragment inside it. Root cause of a production regression (2026-07-22):
 * TgsCanvas's addRootBlock() → openDraftEditor() forces a full-tree re-render
 * (`$this->forceRender()`) in the SAME request that auto-mounts `editBlockAction`. Filament's
 * modal-open signal (`sync-action-modals` → `open-modal`) is dispatched via Livewire's
 * `supportDispatches.js`, which defers it by three nested `queueMicrotask()` calls — a FIXED
 * delay, independent of whether Alpine has finished `initTree()`-binding the just-morphed
 * subtree. Any extra markup morphed into TgsCanvas's own root (even two nearly-empty divs)
 * was enough to push Alpine's directive-binding past that fixed window on this machine,
 * silently losing the `open-modal` event for the freshly-inserted action-modal (confirmed via
 * live timeline: `morph.added` for the modal fired before `open-modal`, yet the modal's own
 * `x-on:open-modal.window` never caught it — a manual re-dispatch, run well after everything
 * settled, opened it instantly). This is a fragility of forceRender()+auto-mount-in-the-same-
 * request, not a placement/position/wire:key issue (all three were tried and ruled out).
 * Keeping the drawer as its own component with its own wire:id means its markup never enters
 * TgsCanvas's morph pass, so it can never again add load to that fragile timing window —
 * regardless of how big the drawer's own content grows in the future.
 */
class TgsBlockHistoryDrawer extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public int $canvasId;

    #[Locked]
    public bool $readonly = false;

    public ?string $historyBlockId = null;

    public function hydrate(): void
    {
        Gate::authorize('tgs.access');
        TgsAuthorization::authorizeCanvas($this->canvas());
    }

    public function mount(int $canvasId, bool $readonly = false): void
    {
        $this->canvasId = $canvasId;
        TgsAuthorization::authorizeCanvas($this->canvas());
        $this->readonly = $readonly;
    }

    private ?Canvas $canvasCache = null;

    private function canvas(): Canvas
    {
        return $this->canvasCache ??= TgsModels::canvas()::findOrFail($this->canvasId);
    }

    private function host(): HostDescriptor
    {
        return $this->canvas()->hostDescriptor();
    }

    private function blockBelongsToHost(string $blockId): bool
    {
        $block = TgsModels::block()::find($blockId);
        if ($block === null) {
            return false;
        }

        $host = $this->host();

        return ($block->owner_container_type === $host->containerType
                && $block->owner_container_id === $host->containerId)
            || $block->owner_container_type === 'orphan';
    }

    private function guardWritable(): void
    {
        abort_if($this->readonly, 403);
        TgsAuthorization::authorizeCanvas($this->canvas());
    }

    /**
     * Non-mutating — must work in read-only, so no guardWritable() call. Listens globally
     * (dispatched from TgsCanvas's _node.blade.php via `wire:click="$dispatch('open-block-history', ...)"`,
     * decoupled from any specific TgsCanvas wire:id). Mounts historyAction() itself — the
     * slide-over IS the "open" state now, no separate historyOpen boolean to track.
     */
    #[On('open-block-history')]
    public function openHistory(string $blockId): void
    {
        if (! $this->blockBelongsToHost($blockId)) {
            return;
        }

        $this->historyBlockId = $blockId;
        $this->mountAction('historyAction');
    }

    /**
     * §13 UX follow-up (2026-07-22): the version LIST itself, native Filament `->slideOver()` —
     * replaces the hand-rolled Alpine backdrop+panel this class used to render inline (see git
     * history). Minimum custom code: no bespoke x-show/x-cloak/x-transition, no manual open/close
     * boolean — Filament's own action-modal machinery owns show/hide, backdrop, and the close
     * button. `registerModalActions([viewDiffAction()])` is REQUIRED, not cosmetic: mounting an
     * action while another is already mounted resolves it as a NESTED action —
     * `resolveAction()`'s `count($parentActions)` branch looks it up via
     * `$parentAction->getModalAction($name)`, NOT the usual "is there a component method named
     * this" check — a plain top-level `viewDiffAction()` method is invisible to that lookup and
     * silently fails to resolve (confirmed: mounting attempt gets immediately unmounted) unless
     * registered here.
     */
    public function historyAction(): Action
    {
        return Action::make('historyAction')
            ->modalHeading(__('tgs::filament.tgs.canvas.history.title'))
            ->modalDescription(__('tgs::filament.tgs.canvas.history.media_warning'))
            ->slideOver()
            ->modalWidth(Width::Large)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('tgs::filament.tgs.canvas.history.close'))
            ->registerModalActions([$this->viewDiffAction()])
            ->modalContent(fn (): View => view('tgs::_history-list', [
                'versions' => $this->getHistoryVersionsProperty(),
                'readonly' => $this->readonly,
                'historyBlockId' => $this->historyBlockId,
            ]));
    }

    /**
     * §13: versions for the currently open history drawer, latest first, each carrying a
     * SHORT summary of what changed against the block's CURRENT `data` (not a version-to-version
     * chain — answers "what would change if I revert to this"). The full diff is rendered lazily,
     * on demand, by viewDiffAction() — computing it eagerly for every row here was cheap CPU-wise
     * but produced an unusably long list for blocks with large rich-text `content` (Task-6-review
     * follow-up, 2026-07-22).
     *
     * @return Collection<int, object{id: int, created_at: Carbon, authorLabel: string, summary: string}>
     */
    public function getHistoryVersionsProperty(): Collection
    {
        if ($this->historyBlockId === null) {
            return collect();
        }

        $block = TgsModels::block()::find($this->historyBlockId);
        if ($block === null) {
            return collect();
        }

        // @phpstan-ignore return.type
        return TgsModels::version()::query()
            ->where('block_id', $this->historyBlockId)
            ->latestFirst()
            ->with('createdBy')
            ->get()
            ->map(fn (BlockVersion $version): object => (object) [
                'id' => $version->id,
                'created_at' => $version->created_at,
                'authorLabel' => TgsModels::authorLabel($version->createdBy) ?? (string) __('tgs::filament.tgs.canvas.history.deleted_user'),
                'summary' => $this->summarizeChange($version->data, $block->data),
            ]);
    }

    /**
     * Short human label for the list row: how many TOP-LEVEL keys differ between this version's
     * snapshot and the block's current data. Deliberately shallow (not a deep/recursive diff) —
     * just enough to give the row meaning before the user opens the full diff via viewDiffAction().
     *
     * @param  array<array-key, mixed>  $old
     * @param  array<array-key, mixed>  $new
     */
    private function summarizeChange(array $old, array $new): string
    {
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);
        $changedCount = count(array_filter(
            $keys,
            fn (int|string $key): bool => ($old[$key] ?? null) !== ($new[$key] ?? null),
        ));

        return trans_choice('tgs::filament.tgs.canvas.history.summary_fields', $changedCount, ['count' => $changedCount]);
    }

    /**
     * §13 UX follow-up: the full diff is rendered here, on demand, in a Screen-width modal —
     * NOT inline in the drawer's own list (see getHistoryVersionsProperty()'s docblock for why).
     * A plain Filament Action modal, not TgsCanvas's fragile forceRender()+auto-mount path — no
     * risk of repeating the 2026-07-22 regression this class's own docblock describes.
     */
    public function viewDiffAction(): Action
    {
        return Action::make('viewDiffAction')
            ->modalHeading(__('tgs::filament.tgs.canvas.history.title'))
            ->modalWidth(Width::Screen)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('tgs::filament.tgs.canvas.history.close'))
            ->modalContent(function (array $arguments): HtmlString {
                $version = TgsModels::version()::query()
                    ->where('id', $arguments['versionId'] ?? null)
                    ->where('block_id', $this->historyBlockId)
                    ->first();
                $block = $this->historyBlockId !== null ? TgsModels::block()::find($this->historyBlockId) : null;

                if ($version === null || $block === null) {
                    return new HtmlString('');
                }

                return new HtmlString(app(BlockVersionDiffer::class)->render($version->data, $block->data));
            });
    }

    /**
     * §13: mutating. Gated by the SAME two checks as editBlockAction — guardWritable() for
     * read-only, then the §12 lock check — because a revert is itself a content edit.
     */
    public function revertVersion(int $versionId): void
    {
        $this->guardWritable();

        if ($this->historyBlockId === null) {
            return;
        }

        if (! $this->blockBelongsToHost($this->historyBlockId) || TgsBlock::isLockedForCurrentUser($this->historyBlockId)) {
            return;
        }

        $reverted = app(CanvasPersistence::class)->revertToVersion($this->host(), $this->historyBlockId, $versionId);

        if ($reverted) {
            Notification::make()
                ->title(__('tgs::filament.tgs.canvas.notifications.version_restored'))
                ->success()
                ->send();

            // TgsCanvas is a SEPARATE Livewire component (see this class's docblock) — reverting
            // here mutates the block's data but doesn't, by itself, cause TgsCanvas's own
            // #[Computed] tree to recompute. Same signal TgsTrashDrawer::restore() sends after
            // restoring a block, caught by TgsCanvas::handleExternalCanvasChange().
            $this->dispatch('tgs-canvas-changed', canvasId: $this->canvasId);
        }

        $this->unmountAction();
    }

    public function render(): View
    {
        return view('tgs::tgs-block-history-drawer');
    }
}
