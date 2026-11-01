<?php

namespace App\Filament\Negocio\Resources\Trabajadors;

use App\Filament\Negocio\Resources\Trabajadors\Pages\ManageTrabajadors;
use App\Filament\Negocio\Resources\Trabajadors\Schemas\TrabajadorForm;
use App\Filament\Negocio\Resources\Trabajadors\Tables\TrabajadorsTable;
use App\Models\Trabajador;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TrabajadorResource extends Resource
{
    protected static ?string $model = Trabajador::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'nombres';

    protected static ?string $slug = 'trabajadores';

    protected static ?string $modelLabel = 'trabajador';

    protected static ?string $pluralModelLabel = 'trabajadores';

    protected static ?string $navigationLabel = 'Trabajadores';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return TrabajadorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrabajadorsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTrabajadors::route('/'),
        ];
    }
}
