<?php

namespace App\Filament\Negocio\Resources\Marcas\Tables;

use App\Filament\Tables\OrdenRegistro;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MarcasTable
{
    public static function configure(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),
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
            ->emptyStateHeading('No hay marcas')
            ->emptyStateDescription('Cargá Toyota o SKF una sola vez y después elegila en el producto.')
            ->emptyStateIcon(Heroicon::OutlinedTag));
    }
}
