<?php

namespace ApiMedia\Tgs\Support;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/** Apply a form edit, not a tree replacement or history restore. */
final class BlockEditData
{
    public function prepareValidation(Schema $schema, bool $writable = true): void
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            $canWrite = $writable && ! $component->isHidden() && ! $component->isDisabled();
            if ($component instanceof Field && ! $canWrite) {
                $component->dehydrated(false)->validatedWhenNotDehydrated(false);
            }
            foreach ($component->getChildSchemas(withHidden: true) as $child) {
                $this->prepareValidation($child, $canWrite);
            }
        }
    }

    public function merge(array $stored, array $submitted, Schema $schema, array $items = []): array
    {
        // Called under the edit action's row lock; compare before writing any fields/history.
        foreach ($items['repeaters'] ?? [] as $path => $original) {
            if (Arr::has($submitted, $path) && Arr::get($stored, $path) !== $original) {
                throw ValidationException::withMessages([
                    $schema->getStatePath().'.'.$path => __('tgs::filament.tgs.canvas.notifications.stale_repeater'),
                ]);
            }
        }

        return $this->mergeSchema($stored, $submitted, $schema, $schema->getStatePath(), $items['items'] ?? []);
    }

    /** Original server-side item state, keyed by Filament's transient UUID paths. */
    public function captureItems(Schema $schema, array $stored): array
    {
        return [
            'items' => $this->captureSchemaItems($schema, $stored, $schema->getStatePath()),
            'repeaters' => $this->captureRepeaters($schema, $stored, $schema->getStatePath()),
        ];
    }

    /** Top-level repeater snapshots include their entire nested trees, including empty lists. */
    private function captureRepeaters(Schema $schema, array $stored, string $root): array
    {
        $repeaters = [];
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Repeater) {
                $path = $this->relativePath($component->getStatePath(), $root);
                $repeaters[$path] = Arr::get($stored, $path);

                continue;
            }
            foreach ($component->getChildSchemas(withHidden: true) as $child) {
                $repeaters += $this->captureRepeaters($child, $stored, $root);
            }
        }

        return $repeaters;
    }

    private function captureSchemaItems(Schema $schema, array $stored, string $root): array
    {
        $items = [];
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Repeater && $component->getSimpleField() === null) {
                $path = $this->relativePath($component->getStatePath(), $root);
                $rows = array_values(Arr::get($stored, $path, []) ?? []);
                foreach (array_values($component->getChildSchemas(withHidden: true)) as $index => $child) {
                    $row = $rows[$index] ?? [];
                    $items[$child->getStatePath()] = $row;
                    $items += $this->captureSchemaItems($child, $row, $child->getStatePath());
                }

                continue;
            }
            foreach ($component->getChildSchemas(withHidden: true) as $child) {
                $items += $this->captureSchemaItems($child, $stored, $root);
            }
        }

        return $items;
    }

    private function relativePath(string $path, string $root): string
    {
        return $root !== '' ? substr($path, strlen($root) + 1) : $path;
    }

    private function mergeSchema(array $stored, array $submitted, Schema $schema, string $root, array $items): array
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component->isHidden() || $component->isDisabled() || ! $component->isDehydrated()) {
                continue;
            }
            if ($component instanceof Field) {
                $path = $this->relativePath($component->getStatePath(), $root);
                if ($path !== 'id' && $path !== '_name' && Arr::has($submitted, $path)) {
                    $value = Arr::get($submitted, $path);
                    if ($component instanceof Repeater && $component->getSimpleField() === null && is_array($value)) {
                        $rows = [];
                        foreach (array_values($component->getChildSchemas()) as $index => $child) {
                            if (array_key_exists($index, $value)) {
                                $rows[] = $this->mergeSchema($items[$child->getStatePath()] ?? [], $value[$index],
                                    $child, $child->getStatePath(), $items);
                            }
                        }
                        $value = $rows;
                    }
                    Arr::set($stored, $path, $value);
                }

                continue;
            }
            foreach ($component->getChildSchemas() as $child) {
                $stored = $this->mergeSchema($stored, $submitted, $child, $root, $items);
            }
        }

        return $stored;
    }
}
