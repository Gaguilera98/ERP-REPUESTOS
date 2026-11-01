<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Pages;

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
