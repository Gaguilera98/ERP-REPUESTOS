<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Actions;

use App\Compras\Montos;
use App\Inventario\CorregirCostoCompra;
use App\Models\NegocioConfiguracion;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use App\Models\TenantUser;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class CorregirCostoAction
{
    public static function make(): Action
    {
        return Action::make('corregirCosto')
            ->label('Corregir costo')
            ->color('gray')
            ->icon(Heroicon::OutlinedCalculator)
            ->visible(function (OrdenCompra $record): bool {
                $usuario = auth('tenant')->user();

                return $usuario instanceof TenantUser
                    && $usuario->can('Corregir:Costo')
                    && $record->detalles->contains(fn (OrdenCompraDetalle $detalle): bool => Montos::comparar((string) $detalle->cantidad_recibida, '0') === 1);
            })
            ->schema(function (OrdenCompra $record): array {
                $record->loadMissing(['detalles.variante', 'detalles.ultimaCorreccionCosto']);

                return [
                    Select::make('detalle_id')
                        ->label('Línea recibida')
                        ->options($record->detalles
                            ->filter(fn (OrdenCompraDetalle $detalle): bool => Montos::comparar((string) $detalle->cantidad_recibida, '0') === 1)
                            ->mapWithKeys(fn (OrdenCompraDetalle $detalle): array => [$detalle->id => $detalle->variante?->sku.' · costo vigente '.$detalle->costoVigente()])
                            ->all())
                        ->required()
                        ->native(false)
                        ->live(),
                    TextInput::make('costo_nuevo')->label('Costo real por unidad base')->prefix(NegocioConfiguracion::codigoMoneda())->required()->numeric()->minValue(0)->live(),
                    Placeholder::make('promedio')
                        ->label('Promedio de lo que queda')
                        ->content(function (Get $get) use ($record): string {
                            $detalle = $record->detalles->firstWhere('id', $get('detalle_id'));
                            $costo = $get('costo_nuevo');
                            if (! $detalle instanceof OrdenCompraDetalle || ! is_numeric($costo)) {
                                return 'Elegí la línea y el costo real.';
                            }

                            return app(CorregirCostoCompra::class)->vistaPrevia($detalle, (string) $costo);
                        }),
                    Textarea::make('observacion')->label('Por qué')->required(),
                ];
            })
            ->action(function (OrdenCompra $record, array $data): void {
                $usuario = auth('tenant')->user();
                $detalle = $record->detalles->firstWhere('id', $data['detalle_id']);
                if (! $usuario instanceof TenantUser || ! $detalle instanceof OrdenCompraDetalle) {
                    return;
                }
                app(CorregirCostoCompra::class)->corregir($detalle, (string) $data['costo_nuevo'], (string) $data['observacion'], $usuario);
            });
    }
}
