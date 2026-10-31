<?php

namespace App\Filament\Negocio\Resources\Marcas;

use App\Filament\Negocio\Resources\Marcas\Pages\ManageMarcas;
use App\Filament\Negocio\Resources\Marcas\Schemas\MarcaForm;
use App\Filament\Negocio\Resources\Marcas\Tables\MarcasTable;
use App\Models\Marca;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MarcaResource extends Resource
{
    protected static ?string $model = Marca::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $slug = 'marcas';

    protected static ?string $modelLabel = 'marca';

    protected static ?string $pluralModelLabel = 'marcas';

    protected static ?string $navigationLabel = 'Marcas';

    protected static string|\UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return MarcaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MarcasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMarcas::route('/'),
        ];
    }
}
