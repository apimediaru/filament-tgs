<?php

namespace ApiMedia\Tgs\Console\Commands;

use ApiMedia\Tgs\Support\TgsBlockRegistry;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Console\Command;
use Livewire\Component;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Spatie\LaravelData\Data;

use function explode;
use function is_a;

class CheckTgsBlocksCommand extends Command
{
    protected $signature = 'tgs:check';

    protected $description = 'Check that editable TGS fields have public properties in their DTOs.';

    public function handle(TgsBlockRegistry $registry): int
    {
        $errors = [];
        foreach ($registry->all() as $class) {
            $descriptor = $class::make();
            $dto = $class::dataClass();
            $form = new class extends Component implements HasSchemas
            {
                use InteractsWithSchemas;

                public array $data = [];
            };
            $schema = Schema::make($form)->statePath('data')->components($descriptor->schema());
            foreach ($schema->getFlatFields(withHidden: true) as $field) {
                $name = $field->getName();
                if ($field instanceof Builder) {
                    $errors[] = $class::blockKey().'.'.$name.' uses Filament Builder; declare a canvas slot instead.';

                    continue;
                }
                if ($descriptor->isChildOnlyBlock() || $dto === null) {
                    continue; // Structural output is owned by the parent DTO.
                }
                // Upload state and the media UUID are transport data: DTOs expose resolved media.
                if ($field instanceof SpatieMediaLibraryFileUpload
                    || ($field instanceof Hidden && $name === 'uuid')
                    || ! $field->isDehydrated()) {
                    continue;
                }
                $property = explode('.', $name, 2)[0];
                if (! $this->hasExportedField($dto, $property)) {
                    $errors[] = $class::blockKey().'.'.$name.' has no public property in '.$dto;
                }
            }
        }
        foreach ($errors as $error) {
            $this->error($error);
        }
        if ($errors !== []) {
            return self::FAILURE;
        }
        $this->info('TGS block schemas match their DTOs.');

        return self::SUCCESS;
    }

    /**
     * Popup stores button_* fields in a nested DTO; common section fields do likewise.
     * This is a structural check, not proof that fromStored() populates the property.
     *
     * @param  class-string  $dto
     * @param  array<string, bool>  $visited
     */
    private function hasExportedField(string $dto, string $field, array $visited = []): bool
    {
        if (isset($visited[$dto])) {
            return false;
        }
        $visited[$dto] = true;
        $reflection = new ReflectionClass($dto);
        if ($reflection->hasProperty($field) && $reflection->getProperty($field)->isPublic()) {
            return true;
        }
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()
                && is_a($type->getName(), Data::class, true)
                && $this->hasExportedField($type->getName(), $field, $visited)) {
                return true;
            }
        }

        return false;
    }
}
