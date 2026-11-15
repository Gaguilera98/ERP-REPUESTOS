<?php

namespace App\Filament\Negocio\Resources\OrdenCompras;

use App\Filament\Negocio\Resources\OrdenCompras\Pages\CreateOrdenCompra;
use App\Filament\Negocio\Resources\OrdenCompras\Pages\EditOrdenCompra;
use App\Filament\Negocio\Resources\OrdenCompras\Pages\ListOrdenCompras;
use App\Filament\Negocio\Resources\OrdenCompras\Pages\ViewOrdenCompra;
use App\Filament\Negocio\Resources\OrdenCompras\Schemas\OrdenCompraForm;
use App\Filament\Negocio\Resources\OrdenCompras\Tables\OrdenComprasTable;
use App\Models\OrdenCompra;
use App\Tenancy\AccesoSucursales;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdenCompraResource extends Resource
{
    protected static ?string $model = OrdenCompra::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $slug = 'compras';

    protected static ?string $modelLabel = 'compra';

    protected static ?string $pluralModelLabel = 'compras';

    protected static ?string $navigationLabel = 'Compras';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return OrdenCompraForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdenComprasTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AccesoSucursales::class)->filtrarAlmacen(parent::getEloquentQuery(), auth('tenant')->user(), 'almacen_destino_id')
            ->with(['proveedor']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrdenCompras::route('/'),
            'create' => CreateOrdenCompra::route('/create'),
            'view' => ViewOrdenCompra::route('/{record}'),
            'edit' => EditOrdenCompra::route('/{record}/edit'),
        ];
    }
}
