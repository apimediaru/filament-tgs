<?php

namespace ApiMedia\Tgs\Filament\Components;

use ApiMedia\Tgs\Livewire\TgsCanvas;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Models\Contracts\HasCanvases;
use ApiMedia\Tgs\Support\TgsModels;
use BackedEnum;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

use function filled;

/**
 * Mounts {@see TgsCanvas} for a given canvas (Этап 1 «канвасы как сущность»): unifies the
 * previously-duplicated `App\Support\Filament\Fields\TgsCanvasField` (thin state-only field,
 * used only by the old ManageAbout FAQ Builder-field, now retired — see `embedded()` below)
 * and this class' own former `forGroup()` mount helper (three call sites: TextGraphicBuilder,
 * Tgs2, CapabilityResource) into one API mirroring the three canvas `kind`s:
 * `for($group)` (host), `named($case)` (named), `embedded()` (embedded).
 *
 * Additionally derives `readonly` from Filament's own disabled-schema propagation —
 * `CanBeDisabled::isDisabled()` walks up the container chain, so a component nested inside a
 * disabled schema (e.g. a `ViewAction` modal) reports `true` on its own — no manual wiring
 * needed at the call sites. See docs/superpowers/specs/2026-07-22-tgs-canvas-readonly-design.md
 * §2/§3 for why this override, rather than a props closure, is the only place this can be
 * computed (`$component` is not injectable into evaluated closures).
 */
class TgsCanvasField extends Livewire
{
    /** Host-kind canvas: mounts on the record's declared group (was `forGroup()`). */
    public static function for(string $group): static
    {
        return static::make(
            TgsCanvas::class,
            fn ((Model&HasCanvases)|null $record): array => [
                'canvasId' => $record?->canvas($group)->id ?? 0,
            ],
        )
            ->key("tgs-{$group}")
            ->visible(fn (?Model $record): bool => $record !== null);
    }

    /**
     * Named-kind canvas: not bound to any Eloquent record. Accepts any `BackedEnum` (not only
     * `ApiMedia\Tgs\Enums\TgsNamedCanvas` — a feature is free to declare its own enum for its named
     * canvases) or a plain string — mirrors `\ApiMedia\Tgs\Support\TgsModels::canvas()::named()`'s own widening, not every named
     * canvas warrants a formal enum case anywhere (see the ManageInfoPage demo blocks).
     */
    public static function named(BackedEnum|string $case): static
    {
        $key = $case instanceof BackedEnum ? (string) $case->value : $case;

        return static::make(
            TgsCanvas::class,
            fn (): array => ['canvasId' => TgsModels::canvas()::named($case)->id],
        )
            ->key("tgs-named-{$key}");
    }

    /**
     * Embedded-kind canvas (Этап 1 «канвасы как сущность», Слайс D): mounted inside a
     * Repeater/Builder item that carries the canvas's own identity as a sibling `content`
     * field (a plain `Hidden::make('content')->default(fn () => \ApiMedia\Tgs\Support\TgsModels::canvas()::createEmbedded()->id)`
     * declared alongside this one at the call site — see ManageAbout FAQ) — this class itself
     * has no Field state of its own, it only reads that sibling's live value via `Get` and
     * mounts {@see TgsCanvas} on it. Mirrors the old (now-retired)
     * `App\Support\Filament\Fields\TgsCanvasField`'s eager-create-on-add behaviour, but the
     * eager-create itself now lives on the `Hidden` field's `default()`, not here.
     *
     * The owning form must synchronize its complete persisted canvas list through
     * \ApiMedia\Tgs\Support\TgsModels::canvas()::claim(ids, ownerKey, cacheKeys), including removal. This display component
     * cannot infer a complete owner list from one repeater item's sibling state.
     */
    public static function embedded(): static
    {
        return static::make(
            TgsCanvas::class,
            fn (Get $get): array => ['canvasId' => (int) ($get('content') ?? 0)],
        )
            ->key('tgs-embedded')
            ->visible(fn (Get $get): bool => filled($get('content')));
    }

    /**
     * @return array<string, mixed>
     */
    public function getComponentProperties(): array
    {
        return [
            ...parent::getComponentProperties(),
            'readonly' => $this->isDisabled(),
        ];
    }
}
