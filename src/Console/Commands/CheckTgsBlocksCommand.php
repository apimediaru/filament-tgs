<?php

namespace ApiMedia\Tgs\Console\Commands;

use ApiMedia\Tgs\Support\TgsBlockRegistry;
use ApiMedia\Tgs\Support\TgsCommonSettings;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Console\Command;
use Livewire\Component;
use Spatie\LaravelData\Support\DataConfig;

class CheckTgsBlocksCommand extends Command
{
    protected $signature = 'tgs:check';

    protected $description = 'Check editable TGS fields, common settings and their DTO paths.';

    public function handle(TgsBlockRegistry $registry): int
    {
        $errors = [];
        $commonDto = app(TgsCommonSettings::class)::dataClass();
        foreach ($registry->all() as $class) {
            $descriptor = $class::make();
            $form = new class extends Component implements HasSchemas
            {
                use InteractsWithSchemas;

                public array $data = [];
            };
            $groups = ['data' => $descriptor->schema(),
                'heading' => $descriptor->fields('getBlockHeadingFields'),
                'section' => $descriptor->fields('getBlockSectionFields'),
                'container' => $descriptor->fields('getBlockContainerFields')];
            $seen = [];
            foreach ($groups as $group => $components) {
                $schema = Schema::make($form)->statePath('data')->components($components);
                foreach ($this->fields($schema) as [$path, $field]) {
                    $key = $class::blockKey().'.'.$path;
                    if ($field instanceof Builder) {
                        $errors[] = "$key uses Filament Builder; declare a canvas slot instead.";

                        continue;
                    }
                    foreach ($seen as $previous => $previousGroup) {
                        if ($path === $previous || ($previousGroup !== $group && (str_starts_with($path, $previous.'.') || str_starts_with($previous, $path.'.')))) {
                            $errors[] = "$key conflicts between $previousGroup and $group.";
                        }
                    }
                    $seen[$path] = $group;
                    if ($field instanceof SpatieMediaLibraryFileUpload || ($field instanceof Hidden && $field->getName() === 'uuid') || ! (clone $field)->dehydratedWhenHidden()->isDehydrated()) {
                        continue;
                    }
                    $dto = $group === 'data' ? $class::dataClass() : $commonDto;
                    if ($dto === null || ($group === 'data' && $descriptor->isChildOnlyBlock())) {
                        continue;
                    }
                    if (! $this->hasPath($dto, $path, $group === 'data')) {
                        $errors[] = "$key has no public property at this path in $dto.";
                    }
                }
            }
        }
        foreach (array_unique($errors) as $error) {
            $this->error($error);
        }
        if ($errors !== []) {
            return self::FAILURE;
        }
        $this->info('TGS block schemas match their DTOs.');

        return self::SUCCESS;
    }

    /** @return list<array{string, Field}> */
    private function fields(Schema $schema): array
    {
        $fields = [];
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Field) {
                $fields[] = [substr($component->getStatePath(), 5), $component];
            }
            // Inspect the item template even when the repeater has no stored items in CLI.
            if ($component instanceof Repeater) {
                $child = $component->getChildSchema();
                if ($child !== null) {
                    $fields = [...$fields, ...$this->fields($child->getClone()->statePath('*'))];
                }
            } else {
                foreach ($component->getChildSchemas(withHidden: true) as $child) {
                    $fields = [...$fields, ...$this->fields($child)];
                }
            }
        }

        return $fields;
    }

    /** Checks reflected paths, not whether fromStored actually fills their values. */
    private function hasPath(string $dto, string $path, bool $flatten, array $visited = []): bool
    {
        if (isset($visited[$dto])) {
            return false;
        }
        $visited[$dto] = true;
        [$head, $tail] = array_pad(explode('.', $path, 2), 2, null);
        $properties = app(DataConfig::class)->getDataClass($dto)->properties;
        if ($property = $properties[$head] ?? null) {
            if ($tail === null) {
                return true;
            }
            $child = $property->type->dataClass;
            if ($child === null) {
                return false;
            }
            if (str_starts_with($tail, '*.')) {
                $tail = substr($tail, 2);
            }

            // The explicit path gets shorter; revisiting a DTO here is not a cycle.
            return $this->hasPath($child, $tail, false);
        }
        if ($flatten) {
            // Existing Popup button_* fields are flattened in storage, nested in output.
            foreach ($properties as $property) {
                $child = $property->type->dataClass;
                if ($child !== null && ! $property->type->kind->isDataCollectable() && $this->hasPath($child, $path, true, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }
}
