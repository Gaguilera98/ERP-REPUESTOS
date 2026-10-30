<?php

namespace App\Filament\Negocio\Resources\Almacens;

use App\Filament\Negocio\Resources\Almacens\Pages\CreateAlmacen;
use App\Filament\Negocio\Resources\Almacens\Pages\EditAlmacen;
use App\Filament\Negocio\Resources\Almacens\Pages\ListAlmacens;
use App\Filament\Negocio\Resources\Almacens\Schemas\AlmacenForm;
use App\Filament\Negocio\Resources\Almacens\Tables\AlmacensTable;
use App\Models\Almacen;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AlmacenResource extends Resource
{
    protected static ?string $model = Almacen::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $slug = 'almacenes';

    protected static ?string $modelLabel = 'almacén';

    protected static ?string $pluralModelLabel = 'almacenes';

    protected static ?string $navigationLabel = 'Almacenes';

    protected static string|\UnitEnum|null $navigationGroup = 'Locales';

    public static function form(Schema $schema): Schema
    {
        return AlmacenForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlmacensTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlmacens::route('/'),
            'create' => CreateAlmacen::route('/create'),
            'edit' => EditAlmacen::route('/{record}/edit'),
        ];
    }
}
