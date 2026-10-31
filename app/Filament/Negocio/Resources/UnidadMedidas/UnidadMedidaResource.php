<?php

namespace App\Filament\Negocio\Resources\UnidadMedidas;

use App\Filament\Negocio\Resources\UnidadMedidas\Pages\ManageUnidadMedidas;
use App\Filament\Negocio\Resources\UnidadMedidas\Schemas\UnidadMedidaForm;
use App\Filament\Negocio\Resources\UnidadMedidas\Tables\UnidadMedidasTable;
use App\Models\UnidadMedida;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UnidadMedidaResource extends Resource
{
    protected static ?string $model = UnidadMedida::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $slug = 'unidades';

    protected static ?string $modelLabel = 'unidad de medida';

    protected static ?string $pluralModelLabel = 'unidades de medida';

    protected static ?string $navigationLabel = 'Unidades';

    protected static string|\UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 0;

    public static function form(Schema $schema): Schema
    {
        return UnidadMedidaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UnidadMedidasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUnidadMedidas::route('/'),
        ];
    }
}
