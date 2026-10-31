<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros;

use App\Filament\Negocio\Resources\ProductoMaestros\Pages\CreateProductoMaestro;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\EditProductoMaestro;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\ListProductoMaestros;
use App\Filament\Negocio\Resources\ProductoMaestros\Schemas\ProductoMaestroForm;
use App\Filament\Negocio\Resources\ProductoMaestros\Tables\ProductoMaestrosTable;
use App\Models\ProductoMaestro;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductoMaestroResource extends Resource
{
    protected static ?string $model = ProductoMaestro::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $slug = 'productos';

    protected static ?string $modelLabel = 'producto';

    protected static ?string $pluralModelLabel = 'productos';

    protected static ?string $navigationLabel = 'Productos';

    protected static string|\UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return ProductoMaestroForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductoMaestrosTable::configure($table);
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
            'index' => ListProductoMaestros::route('/'),
            'create' => CreateProductoMaestro::route('/create'),
            'edit' => EditProductoMaestro::route('/{record}/edit'),
        ];
    }
}
