<?php

namespace App\Filament\Negocio\Resources\Sucursals;

use App\Filament\Negocio\Resources\Sucursals\Pages\CreateSucursal;
use App\Filament\Negocio\Resources\Sucursals\Pages\EditSucursal;
use App\Filament\Negocio\Resources\Sucursals\Pages\ListSucursals;
use App\Filament\Negocio\Resources\Sucursals\Schemas\SucursalForm;
use App\Filament\Negocio\Resources\Sucursals\Tables\SucursalsTable;
use App\Models\Sucursal;
use App\Tenancy\AccesoSucursales;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SucursalResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        return app(AccesoSucursales::class)->filtrarSucursal(parent::getEloquentQuery(), auth('tenant')->user(), 'sucursales.id');
    }

    protected static ?string $model = Sucursal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $slug = 'sucursales';

    protected static ?string $modelLabel = 'sucursal';

    protected static ?string $pluralModelLabel = 'sucursales';

    protected static ?string $navigationLabel = 'Sucursales';

    protected static string|\UnitEnum|null $navigationGroup = 'Locales';

    public static function form(Schema $schema): Schema
    {
        return SucursalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SucursalsTable::configure($table);
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
            'index' => ListSucursals::route('/'),
            'create' => CreateSucursal::route('/create'),
            'edit' => EditSucursal::route('/{record}/edit'),
        ];
    }
}
