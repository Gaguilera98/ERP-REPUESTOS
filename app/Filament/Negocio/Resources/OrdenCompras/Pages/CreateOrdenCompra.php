<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Pages;

use App\Compras\GuardarOrdenCompra;
use App\Filament\Negocio\Resources\OrdenCompras\OrdenCompraResource;
use App\Models\OrdenCompra;
use App\Models\TenantUser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateOrdenCompra extends CreateRecord
{
    protected static string $resource = OrdenCompraResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser) {
            throw new RuntimeException('La compra la carga un usuario del negocio.');
        }

        return app(GuardarOrdenCompra::class)->crear($data, $usuario);
    }

    protected function getRedirectUrl(): string
    {
        $record = $this->getRecord();

        if (! $record instanceof OrdenCompra) {
            return $this->getResource()::getUrl('index');
        }

        return $this->getResource()::getUrl('edit', ['record' => $record]);
    }
}
