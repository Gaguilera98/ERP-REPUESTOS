<?php

namespace App\Policies;

use App\Enums\EstadoOrdenCompra;
use App\Models\OrdenCompra;
use App\Models\TenantUser;
use App\Policies\Concerns\ChecksTenantPermission;
use App\Tenancy\AccesoSucursales;

class OrdenCompraPolicy
{
    use ChecksTenantPermission;

    public function viewAny(TenantUser $user): bool
    {
        return $this->allows($user, 'ViewAny:OrdenCompra');
    }

    public function view(TenantUser $user, OrdenCompra $ordenCompra): bool
    {
        $acceso = app(AccesoSucursales::class);

        return $this->allows($user, 'View:OrdenCompra')
            && $acceso->autorizaAlmacen($user, (int) $ordenCompra->almacen_destino_id);
    }

    public function create(TenantUser $user): bool
    {
        return $this->allows($user, 'Create:OrdenCompra');
    }

    public function update(TenantUser $user, OrdenCompra $ordenCompra): bool
    {
        return $this->view($user, $ordenCompra) && $this->allows($user, 'Update:OrdenCompra');
    }

    public function delete(TenantUser $user, OrdenCompra $ordenCompra): bool
    {
        return $ordenCompra->estado === EstadoOrdenCompra::Pendiente
            && $this->allows($user, 'Delete:OrdenCompra');
    }

    public function deleteAny(TenantUser $user): bool
    {
        return $this->allows($user, 'DeleteAny:OrdenCompra');
    }
}
