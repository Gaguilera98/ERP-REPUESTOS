<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Tables;

use App\Enums\EstadoOrdenCompra;
use App\Filament\Negocio\Resources\OrdenCompras\Actions\AnularOrdenCompraAction;
use App\Filament\Negocio\Resources\OrdenCompras\Actions\RecibirMercaderiaAction;
use App\Filament\Tables\OrdenRegistro;
use App\Models\NegocioConfiguracion;
use App\Models\OrdenCompra;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdenComprasTable
{
    public static function configure(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->columns([
                TextColumn::make('id')
                    ->label('Compra'),
                TextColumn::make('proveedor.nombre_empresa')
                    ->label('Proveedor')
                    ->searchable(),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoOrdenCompra $state): string => $state->etiqueta()),
                TextColumn::make('total')
                    ->label('Total')
                    ->prefix(NegocioConfiguracion::codigoMoneda())
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('fecha_emision')
                    ->label('Emisión')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([
                RecibirMercaderiaAction::make(),
                AnularOrdenCompraAction::make(),
                ViewAction::make()
                    ->visible(fn (OrdenCompra $record): bool => $record->estado !== EstadoOrdenCompra::Pendiente),
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (OrdenCompra $record): bool => $record->estado === EstadoOrdenCompra::Pendiente),
            ])
            ->emptyStateHeading('No hay compras')
            ->emptyStateDescription('Cargá el pedido y recibí la mercadería cuando llegue. Ahí se confirma el costo.')
            ->emptyStateIcon(Heroicon::OutlinedShoppingCart));
    }
}
