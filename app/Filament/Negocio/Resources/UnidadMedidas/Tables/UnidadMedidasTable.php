<?php

namespace App\Filament\Negocio\Resources\UnidadMedidas\Tables;

use App\Enums\MagnitudUnidad;
use App\Filament\Tables\OrdenRegistro;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnidadMedidasTable
{
    public static function configure(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('simbolo')
                    ->label('Símbolo')
                    ->badge(),
                TextColumn::make('magnitud')
                    ->label('Magnitud')
                    ->formatStateUsing(function (mixed $state): string {
                        if ($state instanceof MagnitudUnidad) {
                            return $state->etiqueta();
                        }

                        return MagnitudUnidad::tryFrom((string) $state)?->etiqueta() ?? (string) $state;
                    })
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay unidades de medida')
            ->emptyStateDescription('Cargá milímetro, pulgada o voltio antes de armar la ficha de una categoría.')
            ->emptyStateIcon(Heroicon::OutlinedScale));
    }
}
