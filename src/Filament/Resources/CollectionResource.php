<?php

namespace ApiMedia\Tgs\Filament\Resources;

use ApiMedia\Tgs\Filament\Clusters\Tgs;
use ApiMedia\Tgs\Filament\Components\TgsCanvasField;
use ApiMedia\Tgs\Filament\Resources\CollectionResource\Pages\CreateCollection;
use ApiMedia\Tgs\Filament\Resources\CollectionResource\Pages\EditCollection;
use ApiMedia\Tgs\Filament\Resources\CollectionResource\Pages\ListCollections;
use ApiMedia\Tgs\Models\Collection;
use ApiMedia\Tgs\Support\TgsModels;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

use function __;
use function app;
use function array_map;
use function filter_var;

/**
 * A standalone resource for named sets of TGS blocks.
 *
 * @extends resource<Collection>
 */
class CollectionResource extends Resource
{
    protected static bool $hasTitleCaseModelLabel = false;

    public static function getRecordRouteKeyName(): ?string
    {
        return app(static::getModel())->getKeyName();
    }

    public static function resolveRecordRouteBinding(int|string $key, ?\Closure $modifyQuery = null): ?Model
    {
        $model = app(static::getModel());
        if ($model->getKeyType() === 'int' && filter_var($key, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        return parent::resolveRecordRouteBinding($key, $modifyQuery);
    }

    protected static ?string $cluster = Tgs::class;

    public static function getModel(): string
    {
        return TgsModels::collection();
    }

    // No own navigationIcon (2026-07-23): the TGS nav group icon (AdminPanelProvider's
    // getNavigationGroups()) is enough — children stay unadorned, unlike CMS where every
    // child still carries its own icon.
    protected static string|\BackedEnum|null $navigationIcon = null;

    public static function getNavigationLabel(): string
    {
        return __('tgs::filament.collections.plural');
    }

    public static function getModelLabel(): string
    {
        return __('tgs::filament.collections.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tgs::filament.collections.plural');
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('tgs.access')
            && Gate::allows('tgs.manageLibrary') && parent::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny() && parent::canDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny() && parent::canDeleteAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('tabs')->tabs([
                Tab::make('general')->label(__('tgs::filament.tab.general'))
                    ->icon('heroicon-o-cog-8-tooth')->schema(static::getGeneralTabSchema()),
            ])->columnSpanFull(),
        ]);
    }

    /**
     * Flat inside the Tgs cluster: the section label would render a redundant collapsible «ТГС»
     * sub-heading in the cluster's sub-navigation. Access control still uses section() above.
     */
    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    protected static function getGeneralTabSchema(): array
    {
        return [
            TextInput::make('name')
                ->label(__('tgs::filament.collections.name'))
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('description')
                ->label(__('tgs::filament.collections.description'))
                ->rows(3)
                ->columnSpanFull(),
            ...array_map(fn (string $group) => Section::make(__("tgs::filament.tgs.slots.{$group}") === "tgs::filament.tgs.slots.{$group}" ? $group : __("tgs::filament.tgs.slots.{$group}"))
                ->schema([TgsCanvasField::for($group)])->compact()->columnSpanFull(), static::getModel()::canvasGroups()),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('tgs::filament.collections.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('tgs::filament.collections.description'))
                    ->limit(50)
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCollections::route('/'),
            'create' => CreateCollection::route('/create'),
            'edit' => EditCollection::route('/{record}/edit'),
        ];
    }
}
