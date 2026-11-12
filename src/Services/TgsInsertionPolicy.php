<?php

namespace ApiMedia\Tgs\Services;

use ApiMedia\Tgs\Enums\TgsNamedCanvas;
use ApiMedia\Tgs\Support\HostDescriptor;
use ApiMedia\Tgs\Support\TgsBlockRegistry;
use ApiMedia\Tgs\Support\TgsHostRegistry;
use ApiMedia\Tgs\Support\TgsModels;
use Illuminate\Validation\ValidationException;

use function in_array;

/** Shared by the palette and every new placement, independent of host app classes. */
final class TgsInsertionPolicy
{
    public function __construct(private TgsBlockRegistry $registry, private BlockChildPolicy $children, private TgsHostRegistry $hosts) {}

    public function allows(string $type, ?string $parentType, ?string $slot, ?HostDescriptor $host = null, ?string $parentId = null): bool
    {
        return $this->allowsStructure($type, $parentType, $slot)
            && ($this->registry->get($type)::allowedHosts() === null || $this->allowsHosts($type, $parentId !== null
                ? $this->destinationHosts('block', $parentId, $slot ?? '', $host)
                : ($host !== null ? [$host] : [])));
    }

    private function allowsStructure(string $type, ?string $parentType, ?string $slot): bool
    {
        $block = $this->registry->get($type);
        if ($block === null || ! $this->registry->isAvailable($type)) {
            return false;
        }
        if ($parentType === null) {
            return ! $block->isChildOnlyBlock();
        }
        if ($block::rootOnly()) {
            return false;
        }
        $parent = $this->registry->get($parentType);
        if ($parent === null || $slot === null) {
            return false;
        }
        $allowed = $this->children->allowedChildrenFor($parent, $slot);

        return $allowed !== [] ? in_array($type, $allowed, true) : ! $block->isChildOnlyBlock();
    }

    public function ensureAvailable(string $type, ?HostDescriptor $host = null): void
    {
        if (! $this->registry->isAvailable($type) || ! $this->allowsHosts($type, $host !== null ? [$host] : [])) {
            $this->refuse();
        }
    }

    public function allowsDestination(string $type, string $containerType, string $containerId, string $slot, ?HostDescriptor $host = null): bool
    {
        $parent = $containerType === 'block' ? TgsModels::block()::find($containerId) : null;
        if ($containerType === 'block' && $parent === null) {
            return false;
        }

        return $this->allowsStructure($type, $parent?->type, $slot)
            && ($this->registry->get($type)::allowedHosts() === null
                || $this->allowsHosts($type, $this->destinationHosts($containerType, $containerId, $slot, $host)));
    }

    public function ensureDestination(string $type, string $containerType, string $containerId, string $slot, ?HostDescriptor $host = null): void
    {
        if (! $this->allowsDestination($type, $containerType, $containerId, $slot, $host)) {
            $this->refuse();
        }
    }

    /** Validate the entire prospective tree against destination roots, never source roots. */
    public function ensureSubtree(string $blockId, string $containerType, string $containerId, string $slot, ?HostDescriptor $host = null): void
    {
        $parent = $containerType === 'block' ? TgsModels::block()::find($containerId) : null;
        if ($containerType === 'block' && $parent === null) {
            $this->refuse();
        }
        $roots = $this->destinationHosts($containerType, $containerId, $slot, $host);
        $visited = [];
        $this->checkTree($blockId, $parent?->type, $slot, $roots, $visited);
    }

    /** @param list<HostDescriptor> $roots
     * @param  array<string, bool>  $visited
     */
    private function checkTree(string $blockId, ?string $parentType, string $slot, array $roots, array &$visited): void
    {
        $block = TgsModels::block()::findOrFail($blockId);
        if (! $this->allowsStructure($block->type, $parentType, $slot) || ! $this->allowsHosts($block->type, $roots)) {
            $this->refuse();
        }
        if (isset($visited[$blockId])) {
            return;
        }
        $visited[$blockId] = true;
        foreach (TgsModels::placement()::query()->where('container_type', 'block')->where('container_id', $blockId)->get() as $child) {
            $this->checkTree($child->block_id, $block->type, $child->slot, $roots, $visited);
        }
    }

    /** @return list<HostDescriptor> */
    private function destinationHosts(string $type, string $id, string $slot, ?HostDescriptor $fallback): array
    {
        if ($type !== 'block') {
            return [new HostDescriptor($type, $id, $slot)];
        }
        $roots = app(CanvasPersistence::class)->resolveUsagePlaces($id, liveOnly: true);

        return $roots !== [] ? $roots : ($fallback !== null ? [$fallback] : []);
    }

    /** @param list<HostDescriptor> $roots */
    private function allowsHosts(string $type, array $roots): bool
    {
        $descriptor = $this->registry->get($type);
        if ($descriptor === null) {
            return false;
        }
        $allowed = $descriptor::allowedHosts();
        if ($allowed === null) {
            return true;
        }
        if ($roots === []) {
            return false;
        }
        foreach ($roots as $root) {
            $hostType = $root->containerType;
            if ($hostType === 'canvas') {
                $canvas = TgsModels::canvas()::find($root->containerId);
                if ($canvas?->kind === 'named' && $canvas->key === TgsNamedCanvas::BlockLibrary->value) {
                    continue;
                }
                $hostType = $canvas?->kind === 'host' ? (string) $canvas->host_type : '';
            }
            if ($this->hosts->isStaging($hostType)) {
                continue;
            }
            $key = $this->hosts->keyFor($hostType);
            if ($key === null || ! in_array($key, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['type' => __('tgs::filament.tgs.insertion_forbidden')]);
    }
}
