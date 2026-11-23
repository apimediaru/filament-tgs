<?php

namespace ApiMedia\Tgs\Support;

use ApiMedia\Tgs\Data\Contracts\ResolvesFromStored;
use ApiMedia\Tgs\Enums\ContainerSize;
use ApiMedia\Tgs\Enums\ContentBlockType;
use ApiMedia\Tgs\Enums\Heading;
use ApiMedia\Tgs\Enums\Position;
use ApiMedia\Tgs\Enums\SectionSize;
use ApiMedia\Tgs\Filament\Concerns\DeclaresBlockContract;
use ApiMedia\Tgs\Models\Block as BlockModel;
use ApiMedia\Tgs\Services\BlockLockResolver;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

abstract class TgsBlock
{
    use DeclaresBlockContract;

    protected string|\BackedEnum|Htmlable|Closure|null $icon = null;

    abstract public static function blockType(): ContentBlockType|string;

    public static function blockKey(): string
    {
        $type = static::blockType();

        return $type instanceof ContentBlockType ? $type->value : $type;
    }

    /** @return class-string<ResolvesFromStored>|null */
    public static function dataClass(): ?string
    {
        return null;
    }

    public static function make(?string $name = null): static
    {
        return new static;
    }

    public function getName(): string
    {
        return static::blockKey();
    }

    public function getBlockLabel(): string|Htmlable|Closure|null
    {
        return static::blockKey();
    }

    public function getIcon(): string|\BackedEnum|Htmlable|null
    {
        return $this->icon instanceof Closure ? ($this->icon)() : $this->icon;
    }

    /** Own editable data fields; sites override this together with dataClass().
     * @return list<mixed>
     */
    public function schema(): array
    {
        return $this->fields();
    }

    /** @return list<mixed> */
    public function fields(string $method = 'getBlockFields'): array
    {
        $fields = $this->{$method}();

        return $fields instanceof Closure ? $fields() : $fields;
    }

    /**
     * A disabled tab hides controls, not stored metadata. Reuse descriptor fields
     * for the keys, without keeping a second hard-coded list of heading/section names.
     */
    public function preserveHiddenMetadata(array $stored, array $data, Schema $context): array
    {
        $hidden = [];
        if (! $this->hasHeadingFields()) {
            $hidden[] = 'getBlockHeadingFields';
        }
        if (! $this->hasSectionFields()) {
            $hidden[] = 'getBlockSectionFields';
        }
        foreach ($hidden as $method) {
            $schema = (clone $context)->components($this->fields($method));
            foreach ($schema->getFlatFields(withHidden: true) as $field) {
                $key = $field->getName();
                if (Arr::has($stored, $key)) {
                    Arr::set($data, $key, Arr::get($stored, $key));
                }
            }
        }

        return $data;
    }

    /** Stable host keys; null allows any host, an empty list allows staging only.
     * @return list<string>|null
     */
    public static function allowedHosts(): ?array
    {
        return null;
    }

    public static function rootOnly(): bool
    {
        return false;
    }

    public static function isAvailable(): bool
    {
        return true;
    }

    public function hasHeadingFields(): bool
    {
        return true;
    }

    public function hasSectionFields(): bool
    {
        return true;
    }

    public static function makeBlockUniqueID(): string
    {
        return (string) Str::ulid();
    }

    public static function isForeignBlock(?string $blockId, ?Model $record): bool
    {
        if (blank($blockId) || $record === null) {
            return false;
        }

        $block = TgsModels::block()::find($blockId);

        if ($block === null || $block->owner_container_type === 'orphan') {
            return false;
        }

        $currentHostAlias = HostDescriptor::forModelSlot($record, '__unused__')->containerType;

        return ! ($block->owner_container_type === $currentHostAlias
            && $block->owner_container_id === (string) $record->getKey());
    }

    public static function isLockedForCurrentUser(?string $blockId): bool
    {
        if (blank($blockId)) {
            return false;
        }

        if (self::currentUserCanManageLocks()) {
            return false;
        }

        return app(BlockLockResolver::class)->isEffectivelyLocked($blockId);
    }

    /**
     * Status indicator, not access control: true whenever the block is effectively
     * locked (direct or inherited cascade), for EVERY role including admin/super-admin.
     * isLockedForCurrentUser() honors tgs.manageLocks because it gates actual restrictions
     * (disabled fields, blocked detach); this method never bypasses anyone, because an
     * an authorized operator still needs to see the current state — otherwise the only
     * signal is which of the Lock/Unlock buttons happens to render in the item's action
     * modal, which is easy to miss.
     */
    public static function isBlockEffectivelyLocked(?string $blockId): bool
    {
        if (blank($blockId)) {
            return false;
        }

        return app(BlockLockResolver::class)->isEffectivelyLocked($blockId);
    }

    public static function currentUserCanManageLocks(): bool
    {
        return Gate::allows('tgs.manageLocks');
    }

    public static function isBlockLocked(?string $blockId): bool
    {
        if (blank($blockId)) {
            return false;
        }

        return (bool) (TgsModels::block()::query()->find($blockId)?->is_locked);
    }

    protected static function resolveMediaHostBlock(?string $blockId): BlockModel
    {
        return TgsModels::block()::firstOrCreate(
            ['id' => $blockId],
            ['type' => static::blockKey(), 'data' => null, ...TgsModels::block()::unclaimedOwner()],
        );
    }

    /**
     * Explicit disk for this block's content media. Use the same value in the editor upload field.
     * Null leaves disk selection to the media collection and the uploader's defaults.
     */
    public static function contentMediaDisk(): ?string
    {
        return null;
    }

    /**
     * Hidden `uuid` field shared by media blocks (Picture/Gallery) to scope their upload
     * field's media to this block instance. Uses `afterStateHydrated()`, not `default()`:
     * `editBlockAction()->fillForm()` always supplies a non-null array (even for a brand-new
     * draft block, whose `data` is `[]`), so Filament's `default()` — only applied by
     * `hydrateDefaultState()` when the schema's fill state is exactly `null` — never fires.
     * `afterStateHydrated()` runs unconditionally, before `fillStateWithNull()` would otherwise
     * leave the field `null`.
     */
    protected static function mediaUuidHiddenField(): Hidden
    {
        return Hidden::make('uuid')
            ->afterStateHydrated(function (Hidden $component, ?string $state): void {
                if (blank($state)) {
                    $component->state(Str::random(10));
                }
            });
    }

    public function getBlockHeadingFields(): array|Closure
    {
        return app(TgsCommonSettings::class)->heading([
            Group::make([
                Textarea::make('section_heading')
                    ->label(__('tgs::filament.tgs.settings.fields.section_heading.label'))
                    ->columnSpanFull(),
                Select::make('section_heading_tag')
                    ->label(__('tgs::filament.tgs.settings.fields.section_heading_tag.label'))
                    ->helperText(__('tgs::filament.tgs.settings.fields.section_heading_tag.help'))
                    ->options(Heading::class)
                    ->native(false),
                Select::make('section_heading_display')
                    ->label(__('tgs::filament.tgs.settings.fields.section_heading_display.label'))
                    ->helperText(__('tgs::filament.tgs.settings.fields.section_heading_display.help'))
                    ->options(Heading::class)
                    ->native(false),
                TextInput::make('section_heading_classname')
                    ->label(__('tgs::filament.tgs.settings.fields.section_heading_classname.label'))
                    ->helperText(__('tgs::filament.tgs.settings.fields.section_heading_classname.help'))
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public function getBlockSectionFields(): array|Closure
    {
        return app(TgsCommonSettings::class)->section([
            Group::make([
                Select::make('section_size')
                    ->label(__('tgs::filament.tgs.settings.fields.section_size.label'))
                    ->options(SectionSize::class)
                    ->native(false),
                Select::make('section_position')
                    ->label(__('tgs::filament.tgs.settings.fields.section_position.label'))
                    ->options(Position::class)
                    ->native(false),
                ColorPicker::make('section_bg')
                    ->label(__('tgs::filament.tgs.settings.fields.section_bg.label')),
                TextInput::make('section_classname')
                    ->label(__('tgs::filament.tgs.settings.fields.section_classname.label'))
                    ->helperText(__('tgs::filament.tgs.settings.fields.section_classname.help')),
            ])->columns(2),
        ]);
    }

    public function getBlockContainerFields(): array|Closure
    {
        return app(TgsCommonSettings::class)->container([
            Select::make('container_size')
                ->label(__('tgs::filament.tgs.settings.fields.container_size.label'))
                ->options(ContainerSize::class)
                ->native(false),
            Select::make('container_position')
                ->label(__('tgs::filament.tgs.settings.fields.container_position.label'))
                ->options(Position::class)
                ->native(false),
        ]);
    }

    public function getBlockFields(): array|Closure
    {
        return [];
    }

    /**
     * Raw text content
     */
    public static function toText(array $data): ?string
    {
        return null;
    }
}
