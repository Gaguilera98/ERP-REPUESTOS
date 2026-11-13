<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Pages;

use App\Filament\Negocio\Resources\OrdenCompras\Actions\CorregirCostoAction;
use App\Filament\Negocio\Resources\OrdenCompras\Actions\ReasignarProveedorFlashAction;
use App\Filament\Negocio\Resources\OrdenCompras\Actions\RecibirMercaderiaAction;
use App\Filament\Negocio\Resources\OrdenCompras\OrdenCompraResource;
use App\Filament\Negocio\Resources\OrdenCompras\Schemas\OrdenCompraForm;
use Filament\Resources\Pages\ViewRecord;

class ViewOrdenCompra extends ViewRecord
{
    protected static string $resource = OrdenCompraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RecibirMercaderiaAction::make(),
            CorregirCostoAction::make(),
            ReasignarProveedorFlashAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return OrdenCompraForm::conLineasGuardadas($data, $this->getRecord());
    }
}
