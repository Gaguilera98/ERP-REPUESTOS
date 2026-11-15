<?php

namespace App\Filament\Negocio\Resources\Almacens;

use App\Filament\Negocio\Resources\Almacens\Pages\ListAlmacens;
use App\Filament\Negocio\Resources\Almacens\Schemas\AlmacenForm;
use App\Filament\Negocio\Resources\Almacens\Tables\AlmacensTable;
// use App\Filament\Negocio\Resources\Almacens\Pages\CreateAlmacen;
// use App\Filament\Negocio\Resources\Almacens\Pages\EditAlmacen;
use App\Models\Almacen;
use App\Tenancy\AccesoSucursales;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AlmacenResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        return app(AccesoSucursales::class)->filtrarAlmacen(parent::getEloquentQuery(), auth('tenant')->user(), 'almacenes.id');
    }

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
            // 'create' => CreateAlmacen::route('/create'),
            // 'edit' => EditAlmacen::route('/{record}/edit'),
        ];
    }
}
