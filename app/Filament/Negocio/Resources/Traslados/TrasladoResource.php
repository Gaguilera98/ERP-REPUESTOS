<?php

namespace App\Filament\Negocio\Resources\Traslados;

use App\Filament\Negocio\Resources\Traslados\Pages\CreateTraslado;
use App\Filament\Negocio\Resources\Traslados\Pages\ListTraslados;
use App\Filament\Negocio\Resources\Traslados\Pages\ViewTraslado;
use App\Models\Traslado;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TrasladoResource extends Resource
{
    protected static ?string $model = Traslado::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $slug = 'traslados';

    protected static ?string $modelLabel = 'traslado';

    protected static ?string $pluralModelLabel = 'traslados';

    protected static ?string $navigationLabel = 'Traslados';

    protected static string|\UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 9;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('Traslado')->formatStateUsing(fn (Traslado $record): string => $record->codigo()),
            TextColumn::make('origen.nombre')->label('Origen'),
            TextColumn::make('destino.nombre')->label('Destino'),
            TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
            TextColumn::make('usuario.name')->label('Registrado por'),
        ])->recordActions([ViewAction::make()->label('Ver traslado')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTraslados::route('/'),
            'create' => CreateTraslado::route('/create'),
            'view' => ViewTraslado::route('/{record}'),
        ];
    }
}
