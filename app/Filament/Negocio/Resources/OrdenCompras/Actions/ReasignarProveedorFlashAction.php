<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Actions;

use App\Compras\ReasignarProveedorFlash;
use App\Enums\EstadoOrdenCompra;
use App\Filament\Negocio\Resources\OrdenCompras\OrdenCompraResource;
use App\Filament\Negocio\Resources\OrdenCompras\Pages\ViewOrdenCompra;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;

class ReasignarProveedorFlashAction
{
    public static function make(): Action
    {
        return Action::make('reasignarProveedor')
            ->label('Asignar proveedor real')
            ->modalDescription('Cambia el proveedor de esta compra express. No vuelve a ingresar mercadería ni modifica costos o caja.')
            ->visible(fn (OrdenCompra $record): bool => $record->es_compra_flash
                && $record->estado === EstadoOrdenCompra::Completada
                && $record->proveedor?->es_ocasional
                && (bool) auth('tenant')->user()?->can('update', $record))
            ->schema([
                Select::make('proveedor_id')
                    ->label('Proveedor real')
                    ->options(fn (): array => Proveedor::query()->where('es_ocasional', false)->orderBy('nombre_empresa')->pluck('nombre_empresa', 'id')->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (OrdenCompra $record, array $data): void {
                app(ReasignarProveedorFlash::class)->ejecutar($record, (int) $data['proveedor_id'], auth('tenant')->user());
            })
            ->after(function (OrdenCompra $record, Action $action): void {
                $record->refresh();

                $livewire = $action->getLivewire();

                if ($livewire instanceof ViewOrdenCompra) {
                    $livewire->redirect(OrdenCompraResource::getUrl('view', ['record' => $record]));
                }
            })
            ->successNotificationTitle('Proveedor asignado');
    }
}
