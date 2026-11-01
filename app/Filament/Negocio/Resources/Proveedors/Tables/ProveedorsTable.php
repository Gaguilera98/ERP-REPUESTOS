<?php

namespace App\Filament\Negocio\Resources\Proveedors\Tables;

use App\Filament\Tables\OrdenRegistro;
use App\Models\Proveedor;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProveedorsTable
{
    public static function configure(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->columns([
                TextColumn::make('nombre_empresa')
                    ->label('Empresa')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nit')
                    ->label('NIT')
                    ->placeholder('—'),
                IconColumn::make('es_ocasional')
                    ->label('Ocasional')
                    ->boolean(),
                TextColumn::make('contactos_count')
                    ->counts('contactos')
                    ->label('Contactos'),
            ])
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->visible(fn (Proveedor $record): bool => ! $record->es_ocasional),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay proveedores')
            ->emptyStateDescription('El proveedor ocasional ya está creado. Sumá los que comprás seguido.')
            ->emptyStateIcon(Heroicon::OutlinedTruck));
    }
}
