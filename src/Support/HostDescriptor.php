<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Models\Canvas;
use ApiMedia\Tgs\Support\Contracts\TgsHost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

use function array_search;
use function class_basename;

class HostDescriptor
{
    public function __construct(
        public string $containerType,
        public string $containerId,
        public string $slot,
    ) {}

    public static function forModel(Model $model, string $column): self
    {
        return self::forModelSlot($model, $column);
    }

    /**
     * Same containerType/containerId resolution as {@see forModel}, but for a host whose
     * slot isn't a plain column (e.g. a composite key inside a JSON blob like
     * `template_data.builder`) — caller supplies the slot string directly.
     */
    public static function forModelSlot(Model $model, string $slot): self
    {
        $alias = array_search($model::class, Relation::morphMap(), true);
        $type = $alias !== false ? $alias : ($model instanceof TgsHost ? $model::tgsHostKey() : Str::snake(class_basename($model)));

        return new self($type, (string) $model->getKey(), $slot);
    }

    public static function forSettings(string $key): self
    {
        return new self('settings', $key, $key);
    }

    /**
     * Named/embedded canvases have no real model host by design (ADR "canvases as entity",
     * Этап 1) — the `canvases` row itself is the container. `containerId` is the canvas's own
     * id; `slot` is a fixed constant since (containerType, containerId) already uniquely
     * addresses the row (one canvas = one block tree), unlike host-kind canvases where several
     * groups can share the same host model.
     */
    public static function forCanvas(Canvas $canvas): self
    {
        return new self('canvas', (string) $canvas->id, 'content');
    }
}
