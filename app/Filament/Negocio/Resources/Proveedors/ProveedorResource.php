<?php

namespace App\Filament\Negocio\Resources\Proveedors;

use App\Filament\Negocio\Resources\Proveedors\Pages\CreateProveedor;
use App\Filament\Negocio\Resources\Proveedors\Pages\EditProveedor;
use App\Filament\Negocio\Resources\Proveedors\Pages\ListProveedors;
use App\Filament\Negocio\Resources\Proveedors\Schemas\ProveedorForm;
use App\Filament\Negocio\Resources\Proveedors\Tables\ProveedorsTable;
use App\Models\Proveedor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProveedorResource extends Resource
{
    protected static ?string $model = Proveedor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'nombre_empresa';

    protected static ?string $slug = 'proveedores';

    protected static ?string $modelLabel = 'proveedor';

    protected static ?string $pluralModelLabel = 'proveedores';

    protected static ?string $navigationLabel = 'Proveedores';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ProveedorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProveedorsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProveedors::route('/'),
            'create' => CreateProveedor::route('/create'),
            'edit' => EditProveedor::route('/{record}/edit'),
        ];
    }
}
