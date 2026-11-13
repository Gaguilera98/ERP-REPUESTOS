<?php

namespace App\Filament\Negocio\Resources\AjusteInventarios\Pages;

use App\Filament\Negocio\Resources\AjusteInventarios\AjusteInventarioResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAjusteInventarios extends ListRecords
{
    protected static string $resource = AjusteInventarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AjusteInventarioResource::ajustePuntual(),
            CreateAction::make()
                ->label('Nuevo conteo')
                ->tooltip('Usalo para comparar las cantidades físicas de uno o varios productos con el stock del almacén. Al guardar se bloquean sus movimientos. Al cerrar el conteo se aplican las diferencias y se desbloquea el almacén.'),
        ];
    }
}
