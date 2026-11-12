<?php

namespace Workbench\App\Filament\Resources;

use ApiMedia\Tgs\Filament\Components\TgsCanvasField;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Workbench\App\Filament\Resources\TgsDemoResource\Pages;
use Workbench\App\Models\TgsDemo;

class TgsDemoResource extends Resource
{
    protected static ?string $model = TgsDemo::class;

    protected static ?string $slug = 'tgs-demos';

    protected static ?string $modelLabel = 'демо ТГС';

    protected static ?string $pluralModelLabel = 'Демо ТГС';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([TextInput::make('name')->label('Название')->required(), TgsCanvasField::for('content')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Название')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTgsDemos::route('/'),
            'create' => Pages\CreateTgsDemo::route('/create'),
            'edit' => Pages\EditTgsDemo::route('/{record}/edit'),
        ];
    }
}
