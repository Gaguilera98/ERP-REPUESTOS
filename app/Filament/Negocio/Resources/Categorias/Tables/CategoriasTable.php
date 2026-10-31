<?php

namespace App\Filament\Negocio\Resources\Categorias\Tables;

use App\Filament\Tables\OrdenRegistro;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoriasTable
{
    public static function configure(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Categoría / Rubro')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('atributos.etiqueta')
                    ->label('Atributos de la ficha')
                    ->badge()
                    ->color('info')
                    ->placeholder('Sin atributos definidos')
                    ->wrap(),

                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),

                TextColumn::make('productos_count')
                    ->counts('productos')
                    ->label('Productos')
                    ->badge()
                    ->color('primary')
                    ->alignCenter(),
            ])
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->stickyModalHeader()
                    ->stickyModalFooter(),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay categorías creadas')
            ->emptyStateDescription('Crea categorías como Rodamientos, Filtros o Frenos y definí la ficha de cada una.')
            ->emptyStateIcon(Heroicon::OutlinedTag));
    }
}
