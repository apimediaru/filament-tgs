<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Services;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Support\Contracts\TgsHost;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Database\Eloquent\Model;
use Throwable;

use function class_basename;
use function is_string;

/** Labels and admin links come from the registered host contract. */
class HostLabelResolver
{
    public function __construct(private readonly HostModelResolver $modelResolver) {}

    /** @return array{label: string, url: string|null} */
    public function describe(HostDescriptor $host): array
    {
        // Named/embedded canvases (Этап 1 "канвасы как сущность") are their own container type
        // ('canvas') — resolved explicitly, but a "Canvas #5" label is meaningless to an admin; the canvas's own
        // kind/key carries the actual meaning, so it's handled separately.
        if ($host->containerType === 'canvas') {
            return $this->describeCanvas($host);
        }

        $model = $this->modelResolver->resolve($host);

        if ($model === null) {
            return ['label' => "{$host->containerType} #{$host->containerId}", 'url' => null];
        }

        return ['label' => $this->labelFor($model), 'url' => $this->urlFor($model)];
    }

    /** @return array{label: string, url: string|null} */
    private function describeCanvas(HostDescriptor $host): array
    {
        $canvas = TgsModels::canvas()::query()->find($host->containerId);

        if ($canvas === null) {
            return ['label' => "canvas #{$host->containerId}", 'url' => null];
        }

        $label = match ($canvas->kind) {
            'named' => TgsNamedCanvas::tryFrom((string) $canvas->key)?->label() ?? (string) $canvas->key,
            'embedded' => __('tgs::filament.tgs.canvas.usage.embedded_canvas', ['id' => $canvas->id]),
            default => class_basename($canvas).' #'.$canvas->id,
        };

        // Neither named nor embedded canvases have a dedicated Filament resource page of their
        // own (they're managed from wherever they're mounted — a settings screen, another
        // resource's tab, ...) — no url to offer.
        return ['label' => $label, 'url' => null];
    }

    private function labelFor(Model $model): string
    {
        if ($model instanceof TgsHost) {
            return $model->tgsLabel();
        }

        $title = $model->getAttribute('title') ?? $model->getAttribute('name');

        if (is_string($title) && $title !== '') {
            return $title;
        }

        return class_basename($model).' #'.$model->getKey();
    }

    private function urlFor(Model $model): ?string
    {
        try {
            return $model instanceof TgsHost ? $model->tgsEditUrl() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
