<?php

namespace App\Filament\Negocio\Resources\Categorias;

use App\Filament\Negocio\Resources\Categorias\Pages\ImportarConfiguracion;
use App\Filament\Negocio\Resources\Categorias\Pages\ManageCategorias;
use App\Filament\Negocio\Resources\Categorias\Schemas\CategoriaForm;
use App\Filament\Negocio\Resources\Categorias\Tables\CategoriasTable;
use App\Models\Categoria;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CategoriaResource extends Resource
{
    protected static ?string $model = Categoria::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $slug = 'categorias';

    protected static ?string $modelLabel = 'categoría';

    protected static ?string $pluralModelLabel = 'categorías';

    protected static ?string $navigationLabel = 'Categorías';

    protected static string|\UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return CategoriaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategorias::route('/'),
            'importar' => ImportarConfiguracion::route('/importar'),
        ];
    }
}
