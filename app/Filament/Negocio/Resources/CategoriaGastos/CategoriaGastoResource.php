<?php

namespace App\Filament\Negocio\Resources\CategoriaGastos;

use App\Filament\Negocio\Resources\CategoriaGastos\Pages\ManageCategoriaGastos;
use App\Filament\Negocio\Resources\CategoriaGastos\Schemas\CategoriaGastoForm;
use App\Filament\Negocio\Resources\CategoriaGastos\Tables\CategoriaGastosTable;
use App\Models\CategoriaGasto;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CategoriaGastoResource extends Resource
{
    protected static ?string $model = CategoriaGasto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Categorías de gasto';

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'categorias-gasto';

    protected static ?string $modelLabel = 'categoría de gasto';

    protected static ?string $pluralModelLabel = 'categorías de gasto';

    public static function form(Schema $schema): Schema
    {
        return CategoriaGastoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriaGastosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategoriaGastos::route('/'),
        ];
    }
}
