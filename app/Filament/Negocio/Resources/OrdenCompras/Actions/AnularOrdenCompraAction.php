<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Actions;

use App\Compras\AnularOrdenCompra;
use App\Enums\EstadoOrdenCompra;
use App\Models\OrdenCompra;
use App\Models\TenantUser;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class AnularOrdenCompraAction
{
    public static function make(): Action
    {
        return Action::make('anular')
            ->label('Anular')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Anular compra')
            ->modalDescription('La mercadería no entró. Anular no escribe kardex.')
            ->modalSubmitActionLabel('Anular')
            ->visible(function (OrdenCompra $record): bool {
                $usuario = auth('tenant')->user();

                return $record->estado === EstadoOrdenCompra::Pendiente
                    && $usuario instanceof TenantUser
                    && $usuario->can('update', $record);
            })
            ->action(function (OrdenCompra $record): void {
                app(AnularOrdenCompra::class)->anular($record);
            })
            ->after(function (OrdenCompra $record): void {
                $record->refresh();
            })
            ->successNotificationTitle('Compra anulada');
    }
}
